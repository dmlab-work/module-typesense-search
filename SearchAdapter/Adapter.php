<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\SearchAdapter;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Client\TypesenseClient;
use DmLab\TypesenseIndexer\Api\IndexNameResolverInterface;
use DmLab\TypesenseSearch\SearchAdapter\Aggregation\Builder as AggregationBuilder;
use DmLab\TypesenseSearch\Exception\UnsupportedSearchRequestException;
use DmLab\TypesenseSearch\SearchAdapter\Query\QueryBuilder;
use DmLab\TypesenseSearch\SearchAdapter\Query\QueryModifier;
use DmLab\TypesenseSearch\SearchAdapter\Response\ResponseProcessor;
use DmLab\TypesenseSearch\SearchAdapter\Schema\ParamsSanitizer;
use DmLab\TypesenseSearch\SearchAdapter\Schema\SchemaReader;
use DmLab\TypesenseSearch\SearchAdapter\StoreResolver;
use Magento\AdvancedSearch\Model\Client\ClientException;
use Magento\Framework\Api\AttributeInterface;
use Magento\Framework\Api\AttributeValue;
use Magento\Framework\Api\CustomAttributesDataInterface;
use Magento\Framework\Api\Search\Document;
use Magento\Framework\Api\Search\DocumentInterface;
use Magento\Framework\Search\AdapterInterface;
use Magento\Framework\Search\RequestInterface;
use Magento\Framework\Search\Response\QueryResponse;
use Magento\CatalogSearch\Model\Indexer\Fulltext;
use Psr\Log\LoggerInterface;

/**
 * Typesense search adapter — the query side of the engine.
 *
 * Pipeline: {@see QueryBuilder} → {@see AggregationBuilder::addAggregations()} → {@see QueryModifier}
 * → {@see ParamsSanitizer} → search the store's collection **alias** → {@see ResponseProcessor}
 * → map hits and facets into a {@see QueryResponse}.
 *
 * The alias name is owned by `typesense-indexer` ({@see IndexNameResolverInterface}); this
 * adapter never rebuilds the `<prefix>_<indexerId>_<storeId>` string itself, so the
 * two never drift. Typesense resolves alias→collection server-side, so the alias is
 * queried directly — no client-side alias lookup on the read path.
 *
 * Error contract: a missing collection (HTTP 404 — the store has not been reindexed
 * yet) and a request Typesense's grammar cannot express (an
 * {@see UnsupportedSearchRequestException} — e.g. a negated wildcard) both
 * degrade to an empty response so the storefront keeps working. A field-not-found error
 * invalidates the cached schema and retries without the failing sort/facet clause (a filter on
 * a missing field degrades to empty); every other Typesense failure is surfaced as a
 * {@see ClientException} so real breakage is loud.
 *
 * Layered-navigation aggregations are requested via {@see AggregationBuilder::addAggregations()}
 * (adds `facet_by`) and mapped from the response's `facet_counts` by
 * {@see AggregationBuilder::build()}; a missing/empty response yields empty buckets, not a fatal.
 */
class Adapter implements AdapterInterface
{
    /**
     * Typesense's hard cap on hits returned per search request (`per_page`/`limit`).
     *
     * Magento asks for the whole match set (`size` up to 10000 — "Show All", REST/GraphQL) so it
     * can paginate IDs in MySQL; a request over this cap is rejected (HTTP 422), so windows larger
     * than one page are read in chunks and concatenated ({@see self::searchWindow()}).
     */
    private const MAX_PER_PAGE = 250;

    /**
     * Custom-attribute code carrying a hit's relevance score, mirroring Magento's ES adapter.
     */
    private const SCORE_ATTRIBUTE = 'score';

    /**
     * Document field holding the entity id, as written by `typesense-indexer`'s DocumentBuilder.
     */
    private const DOCUMENT_ID_FIELD = 'id';

    /**
     * Round-trip count past which a "Show All" window fan-out is logged for operator visibility.
     *
     * A `pageSize` over {@see self::MAX_PER_PAGE} is read in sequential chunks
     * ({@see self::searchWindow()}); beyond this many chunks the read amplification is worth surfacing.
     */
    private const FAN_OUT_LOG_THRESHOLD = 10;

    /**
     * Typesense 30.x message for a field the schema lacks: groups are kind (`facet `/`filter `), field, sort suffix.
     */
    private const FIELD_NOT_FOUND_PATTERN
        = '/Could not find a (facet |filter )?field named `([^`]+)` in the schema( for sorting)?/';

    /**
     * Retries after a field-not-found error: the schema is refreshed once and the search retried once.
     */
    private const MAX_FIELD_RETRIES = 1;

    /**
     * Params that shape the returned page rather than the match set; excluded from the price replay.
     */
    private const NON_MATCH_KEYS = [
        'page' => true,
        'per_page' => true,
        'offset' => true,
        'limit' => true,
        'sort_by' => true,
        'facet_by' => true,
        'max_facet_values' => true,
        'facet_query' => true,
        'include_fields' => true,
        'exclude_fields' => true,
        'highlight_fields' => true,
        'highlight_full_fields' => true,
        'group_by' => true,
        'group_limit' => true,
    ];

    /**
     * @param QueryBuilder $queryBuilder request → Typesense search params
     * @param QueryModifier $queryModifier di-sorted modifier pipeline applied after the builder
     * @param AggregationBuilder $aggregationBuilder adds `facet_by` and maps `facet_counts` → aggregations
     * @param IndexNameResolverInterface $indexNameResolver indexer-owned alias name authority
     * @param StoreResolver $storeResolver resolves the request dimension to a store id
     * @param TypesenseClient $client core's sole Typesense REST client
     * @param LoggerInterface $logger
     * @param SchemaReader $schemaReader cached alias schema, invalidated on a field-not-found error
     * @param ParamsSanitizer $paramsSanitizer drops clauses on undeclared fields, and the failing one before a retry
     * @param ResponseProcessor $responseProcessor di-sorted pipeline applied to the raw response
     */
    public function __construct(
        private readonly QueryBuilder $queryBuilder,
        private readonly QueryModifier $queryModifier,
        private readonly AggregationBuilder $aggregationBuilder,
        private readonly IndexNameResolverInterface $indexNameResolver,
        private readonly StoreResolver $storeResolver,
        private readonly TypesenseClient $client,
        private readonly LoggerInterface $logger,
        private readonly SchemaReader $schemaReader,
        private readonly ParamsSanitizer $paramsSanitizer,
        private readonly ResponseProcessor $responseProcessor
    ) {
    }

    /**
     * @inheritDoc
     *
     * @throws ClientException on any non-404 Typesense failure
     */
    public function query(RequestInterface $request): QueryResponse
    {
        $storeId = $this->storeResolver->resolve($request);

        $alias = $this->indexNameResolver->getAliasName(Fulltext::INDEXER_ID, $storeId);
        $schema = $this->schemaReader->get($alias);

        try {
            $params = $this->queryBuilder->build($request, $schema);
            $params = $this->aggregationBuilder->addAggregations($params, $request, $schema);
            $params = $this->queryModifier->modify($params, $request);
        } catch (UnsupportedSearchRequestException $e) {
            // A request carries a construct Typesense's grammar cannot express (e.g. a negated
            // wildcard or a filter on a field the schema lacks). Degrade to an empty response
            // so the storefront page renders, rather than fataling with a 500.
            $this->logger->warning('Unsupported Typesense search request: ' . $e->getMessage());

            return new QueryResponse([], $this->aggregationBuilder->build($request, []), 0);
        }
        $params = $this->paramsSanitizer->sanitize($params, $schema);

        // A real text search with no searchable fields (misconfigured store) would make
        // Typesense reject an empty `query_by` (HTTP 400) — degrade to empty, as Suggestions does.
        if (($params['q'] ?? '*') !== '*' && ($params['query_by'] ?? '') === '') {
            return new QueryResponse([], $this->aggregationBuilder->build($request, []), 0);
        }

        $raw = $this->responseProcessor->process($this->search($alias, $params), $params, $request);

        $documents = $this->mapDocuments($raw['hits'] ?? []);
        $total = isset($raw['found']) ? (int)$raw['found'] : count($documents);

        // Dynamic price aggregations are computed over the whole match set, so hand the aggregation
        // builder the same query the search ran (not the page's returned ids). Mirrors the ES adapter
        // seeding its aggregation builder with a QueryContainer of the full query.
        $matchQuery = $this->matchQuery($params);

        return new QueryResponse(
            $documents,
            $this->aggregationBuilder->build($request, $raw, $matchQuery, $storeId, $schema),
            $total
        );
    }

    /**
     * Final params minus {@see self::NON_MATCH_KEYS}, replayed for dynamic price statistics.
     *
     * Modifier params such as `vector_query` and `pinned_hits` are kept, so price ranges match the searched set.
     *
     * @param array<string,mixed> $params final Typesense search params (post modifier pipeline)
     * @return array<string,mixed>
     */
    private function matchQuery(array $params): array
    {
        return array_diff_key($params, self::NON_MATCH_KEYS);
    }

    /**
     * Execute the search against a collection alias, paging when the window exceeds Typesense's cap.
     *
     * @param string $alias collection alias to query
     * @param array<string,scalar> $params Typesense search parameters
     * @return array<string,mixed> decoded Typesense response, or an empty array when the collection is absent
     * @throws ClientException
     */
    private function search(string $alias, array $params): array
    {
        $perPage = isset($params['per_page']) ? (int)$params['per_page'] : 0;
        if ($perPage <= self::MAX_PER_PAGE) {
            return $this->execute($alias, $params);
        }

        return $this->searchWindow($alias, $params, $perPage);
    }

    /**
     * Read a result window larger than {@see self::MAX_PER_PAGE} by paging Typesense in chunks.
     *
     * `found` and `facet_counts` are computed over the whole match set independent of pagination,
     * so they are taken from the first chunk; only `hits` are concatenated across chunks.
     *
     * @param string $alias collection alias to query
     * @param array<string,scalar> $params Typesense search parameters (`per_page`/`page` window)
     * @param int $limit total hits the window asks for
     * @return array<string,mixed>
     * @throws ClientException
     */
    private function searchWindow(string $alias, array $params, int $limit): array
    {
        $roundTrips = (int)ceil($limit / self::MAX_PER_PAGE);
        if ($roundTrips > self::FAN_OUT_LOG_THRESHOLD) {
            $this->logger->warning(
                sprintf(
                    'Typesense "Show All" search fans a %d-hit window into %d sequential requests '
                    . '(per_page cap %d) on alias "%s"; this amplifies read load.',
                    $limit,
                    $roundTrips,
                    self::MAX_PER_PAGE,
                    $alias
                )
            );
        }

        $page = isset($params['page']) ? max(1, (int)$params['page']) : 1;
        $offset = ($page - 1) * $limit;
        unset($params['per_page'], $params['page']);

        $base = null;
        $hits = [];
        $fetched = 0;
        while ($fetched < $limit) {
            $chunk = min(self::MAX_PER_PAGE, $limit - $fetched);
            $params['offset'] = $offset + $fetched;
            $params['limit'] = $chunk;

            $response = $this->execute($alias, $params);
            if ($response === []) {
                // Missing collection (404) — degrade to the hits gathered so far (empty on chunk one).
                break;
            }
            if ($base === null) {
                $base = $response;
            }

            $chunkHits = $response['hits'] ?? [];
            foreach ($chunkHits as $hit) {
                $hits[] = $hit;
            }
            if (count($chunkHits) < $chunk) {
                break;
            }
            $fetched += count($chunkHits);
        }

        if ($base === null) {
            return [];
        }
        $base['hits'] = $hits;

        return $base;
    }

    /**
     * Run a single Typesense search, degrading gracefully on a missing collection or field.
     *
     * @param string $alias collection alias to query
     * @param array<string,scalar> $params Typesense search parameters
     * @param int $retries field-not-found retries already spent
     * @return array<string,mixed> decoded Typesense response, or an empty array when the collection is absent
     * @throws ClientException
     */
    private function execute(string $alias, array $params, int $retries = 0): array
    {
        try {
            return $this->client->request(
                'GET',
                '/collections/' . $alias . '/documents/search',
                null,
                $params
            );
        } catch (TypesenseException $e) {
            if ($this->isMissingCollection($e)) {
                // Collection/alias not present yet (store not reindexed) — an empty
                // result keeps the storefront working instead of fataling.
                $this->logger->warning(
                    sprintf('Typesense collection "%s" is not available: %s', $alias, $e->getMessage())
                );

                return [];
            }

            $missing = $this->missingField($e);
            if ($missing !== null) {
                [$key, $field] = $missing;
                $this->invalidateStaleSchema($alias, $field);
                if ($key === 'filter_by') {
                    $this->logger->critical(sprintf(
                        'Typesense filter field "%s" is missing in alias "%s"; returning no results.',
                        $field,
                        $alias
                    ));

                    return [];
                }
                if ($key !== null
                    && $retries < self::MAX_FIELD_RETRIES
                    && $this->paramsSanitizer->hasField($params, $key, $field)
                ) {
                    $this->logger->critical(sprintf(
                        'Typesense %s field "%s" is missing in alias "%s"; retrying without it.',
                        $key,
                        $field,
                        $alias
                    ));

                    return $this->execute(
                        $alias,
                        $this->paramsSanitizer->removeField($params, $key, $field),
                        $retries + 1
                    );
                }
            }

            $this->logger->critical($e);

            throw new ClientException('Could not perform Typesense search query.', $e->getCode(), $e);
        }
    }

    /**
     * Drop the cached schema after a field-not-found error, unless only a regex declaration covers the field.
     *
     * A regex-covered name (`position_category_<new id>`) is missing until a document carries it; a re-read
     * cannot help, so invalidating would only defeat the cache on every such request.
     *
     * @param string $alias
     * @param string $field
     */
    private function invalidateStaleSchema(string $alias, string $field): void
    {
        $schema = $this->schemaReader->get($alias);
        if ($schema !== null && $schema->has($field) && !$schema->declaresExactly($field)) {
            return;
        }

        $this->schemaReader->invalidate($alias);
    }

    /**
     * Param key and field of a Typesense field-not-found error, or null for any other failure.
     *
     * The key is `sort_by`, `facet_by` or `filter_by`; null for a `query_by` field, which is not retried.
     *
     * @param TypesenseException $e
     * @return array{0:?string,1:string}|null
     */
    private function missingField(TypesenseException $e): ?array
    {
        $message = (string)($e->getResponseBody()['message'] ?? $e->getMessage());
        if (preg_match(self::FIELD_NOT_FOUND_PATTERN, $message, $match) !== 1) {
            return null;
        }

        $key = match (true) {
            $match[1] === 'facet ' => 'facet_by',
            $match[1] === 'filter ' => 'filter_by',
            ($match[3] ?? '') !== '' => 'sort_by',
            default => null,
        };

        return [$key, $match[2]];
    }

    /**
     * Whether a Typesense failure is a genuinely missing collection (safe to degrade to empty).
     *
     * @param TypesenseException $e
     */
    private function isMissingCollection(TypesenseException $e): bool
    {
        if ($e->getStatusCode() !== 404) {
            return false;
        }

        $message = (string)($e->getResponseBody()['message'] ?? $e->getMessage());

        return stripos($message, 'Collection not found') !== false;
    }

    /**
     * Map Typesense hits to Magento search documents (id + score), preserving hit order.
     *
     * @param array<int,array<string,mixed>> $hits
     * @return Document[]
     */
    private function mapDocuments(array $hits): array
    {
        $documents = [];
        foreach ($hits as $hit) {
            $id = $hit['document'][self::DOCUMENT_ID_FIELD] ?? null;
            if ($id === null) {
                continue;
            }

            $documents[] = new Document(
                [
                    DocumentInterface::ID => $id,
                    CustomAttributesDataInterface::CUSTOM_ATTRIBUTES => [
                        self::SCORE_ATTRIBUTE => new AttributeValue(
                            [
                                AttributeInterface::ATTRIBUTE_CODE => self::SCORE_ATTRIBUTE,
                                AttributeInterface::VALUE => $hit['text_match'] ?? 0,
                            ]
                        ),
                    ],
                ]
            );
        }

        return $documents;
    }
}
