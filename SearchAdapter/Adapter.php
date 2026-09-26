<?php
/**
 * Copyright © DMLab. All rights reserved.
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
 * Translates a Magento {@see RequestInterface} into a Typesense search (via
 * {@see QueryBuilder} + the {@see QueryModifier} pipeline), executes it against the
 * store's collection **alias**, and maps the hits back into a {@see QueryResponse}.
 *
 * The alias name is owned by `typesense-indexer` ({@see IndexNameResolverInterface}); this
 * adapter never rebuilds the `<prefix>_<indexerId>_<storeId>` string itself, so the
 * two never drift. Typesense resolves alias→collection server-side, so the alias is
 * queried directly — no client-side alias lookup on the read path.
 *
 * Error contract: a missing collection (HTTP 404 — the store has not been reindexed
 * yet) and a request Typesense's grammar cannot express (an
 * {@see UnsupportedSearchRequestException} — e.g. Advanced Search's SKU wildcard) both
 * degrade to an empty response so the storefront keeps working; every other Typesense
 * failure is surfaced as a {@see ClientException} so real breakage is loud.
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
     * @param QueryBuilder $queryBuilder request → Typesense search params
     * @param QueryModifier $queryModifier di-sorted modifier pipeline applied after the builder
     * @param AggregationBuilder $aggregationBuilder adds `facet_by` and maps `facet_counts` → aggregations
     * @param IndexNameResolverInterface $indexNameResolver indexer-owned alias name authority
     * @param StoreResolver $storeResolver resolves the request dimension to a store id
     * @param TypesenseClient $client core's sole Typesense REST client
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly QueryBuilder $queryBuilder,
        private readonly QueryModifier $queryModifier,
        private readonly AggregationBuilder $aggregationBuilder,
        private readonly IndexNameResolverInterface $indexNameResolver,
        private readonly StoreResolver $storeResolver,
        private readonly TypesenseClient $client,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     *
     * @throws ClientException on any non-404 Typesense failure
     */
    public function query(RequestInterface $request): QueryResponse
    {
        try {
            $params = $this->queryModifier->modify($this->queryBuilder->build($request), $request);
        } catch (UnsupportedSearchRequestException $e) {
            // A request carries a construct Typesense's grammar cannot express — e.g. Advanced
            // Search's SKU `wildcardFilter` (advanced_search_container). Degrade to an empty
            // response so the storefront page renders, rather than fataling with a 500.
            $this->logger->warning('Unsupported Typesense search request: ' . $e->getMessage());

            return new QueryResponse([], $this->aggregationBuilder->build($request, []), 0);
        }

        // A real text search with no searchable fields (misconfigured store) would make
        // Typesense reject an empty `query_by` (HTTP 400) — degrade to empty, as Suggestions does.
        if (($params['q'] ?? '*') !== '*' && ($params['query_by'] ?? '') === '') {
            return new QueryResponse([], $this->aggregationBuilder->build($request, []), 0);
        }

        $params = $this->aggregationBuilder->addAggregations($params, $request);
        $storeId = $this->storeResolver->resolve($request);
        $alias = $this->indexNameResolver->getAliasName(Fulltext::INDEXER_ID, $storeId);

        $raw = $this->search($alias, $params);

        $documents = $this->mapDocuments($raw['hits'] ?? []);
        $total = isset($raw['found']) ? (int)$raw['found'] : count($documents);

        // Dynamic price aggregations are computed over the whole match set, so hand the aggregation
        // builder the same query the search ran (not the page's returned ids). Mirrors the ES adapter
        // seeding its aggregation builder with a QueryContainer of the full query.
        $matchQuery = $this->matchQuery($params);

        return new QueryResponse(
            $documents,
            $this->aggregationBuilder->build($request, $raw, $matchQuery, $storeId),
            $total
        );
    }

    /**
     * The match-set defining params (`q`/`query_by`/`query_by_weights`/`filter_by`) of the search,
     * for the aggregation builder to replay dynamic price statistics over the whole result set.
     *
     * @param array<string,mixed> $params final Typesense search params (post modifier pipeline)
     * @return array<string,mixed>
     */
    private function matchQuery(array $params): array
    {
        return array_intersect_key(
            $params,
            ['q' => true, 'query_by' => true, 'query_by_weights' => true, 'filter_by' => true]
        );
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
     * Run a single Typesense search, degrading gracefully on a missing collection.
     *
     * @param string $alias collection alias to query
     * @param array<string,scalar> $params Typesense search parameters
     * @return array<string,mixed> decoded Typesense response, or an empty array when the collection is absent
     * @throws ClientException
     */
    private function execute(string $alias, array $params): array
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

            $this->logger->critical($e);

            throw new ClientException('Could not perform Typesense search query.', $e->getCode(), $e);
        }
    }

    /**
     * Whether a Typesense failure is a genuinely missing collection (safe to degrade to empty).
     *
     * Typesense returns HTTP 404 not only for a missing collection but also for a `sort_by` on a
     * non-`sort` field or a `facet_by` on a non-`facet` field — foreseeable when an attribute is
     * exposed for sorting/filtering by Magento but the schema PATCH has not landed (or, for
     * multiselect attributes, is deliberately not sortable). Only the missing-collection case may
     * degrade to an empty response; a field-level 404 must surface as a {@see ClientException} so a
     * mis-flagged attribute fails loudly instead of silently emptying the whole listing.
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
