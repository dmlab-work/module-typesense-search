<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\SearchAdapter\Aggregation;

use DmLab\TypesenseSearch\SearchAdapter\Dynamic\DataProvider;
use DmLab\TypesenseSearch\SearchAdapter\Schema\CollectionSchema;
use DmLab\TypesenseSearch\SearchAdapter\Schema\DroppedFieldLogger;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Search\Dynamic\Algorithm\Repository as AlgorithmRepository;
use Magento\Framework\Search\Dynamic\DataProviderFactory;
use Magento\Framework\Search\Dynamic\EntityStorageFactory;
use Magento\Framework\Search\Request\BucketInterface;
use Magento\Framework\Search\RequestInterface;
use Magento\Framework\Search\Response\Aggregation;
use Magento\Framework\Search\Response\Aggregation\Value;
use Magento\Framework\Search\Response\Bucket;

/**
 * Builds Magento layered-navigation aggregations from Typesense `facet_counts`.
 *
 * Two responsibilities, both driven by the request's aggregation buckets
 * ({@see RequestInterface::getAggregation()}):
 *  - {@see self::addAggregations()} injects `facet_by` into the search params so Typesense
 *    returns facet counts for every requested field;
 *  - {@see self::build()} maps the returned `facet_counts` into a
 *    {@see Aggregation} of {@see Bucket}s the storefront reads via `Fulltext\Collection::getFacetedData`.
 *
 * Counts are correct by construction: Typesense computes `facet_counts` over the **whole match
 * set**, not the returned page, so faceting is independent of pagination.
 *
 * Bucket types:
 *  - term (`termBucket`) → one value per distinct facet value, `{value, count}`, read straight
 *    from the response `facet_counts`;
 *  - dynamic (`dynamicBucket`, price) → the configured price algorithm (`Auto`/`Improved`/`Manual`,
 *    from `$bucket->getMethod()`) is run over the match set via the framework
 *    {@see AlgorithmRepository}, consuming our engine-resolved {@see DataProviderFactory} data
 *    provider (and its {@see \DmLab\TypesenseSearch\SearchAdapter\Aggregation\Interval}).
 *    This mirrors Magento's ES `Aggregation\Builder\Dynamic` so the admin "Price Navigation Step
 *    Calculation" setting is honoured and ranges match OpenSearch on identical data.
 *
 * A missing facet (field absent from the response, e.g. the collection was not reindexed yet)
 * yields an **empty** bucket rather than a fatal, so the storefront degrades gracefully.
 */
class Builder
{
    /**
     * Upper bound on distinct facet values Typesense returns per field.
     *
     * Layered navigation needs every value (not Typesense's default of 10) or the counts are
     * truncated. 10000 comfortably covers real attribute cardinality without unbounded payloads.
     */
    private const MAX_FACET_VALUES = 10000;

    /**
     * Reserved Typesense document id key each hit carries under `document`.
     */
    private const DOCUMENT_ID_FIELD = 'id';

    /**
     * @param AlgorithmRepository $algorithmRepository resolves the price algorithm by bucket method
     * @param EntityStorageFactory $entityStorageFactory seeds the algorithm's `EntityStorage` with the
     *        page's matched ids; the framework `Auto` algorithm short-circuits to an empty result when
     *        the storage is empty ({@see \Magento\Framework\Search\Dynamic\Algorithm\Auto::getItems()}),
     *        so a non-empty storage is required — the ids themselves are unused by our data provider,
     *        which walks the match-set query, exactly as ES seeds it from its own result hits
     * @param DataProviderFactory $dataProviderFactory engine-resolved price data provider (ours)
     * @param DroppedFieldLogger $droppedFieldLogger
     */
    public function __construct(
        private readonly AlgorithmRepository $algorithmRepository,
        private readonly EntityStorageFactory $entityStorageFactory,
        private readonly DataProviderFactory $dataProviderFactory,
        private readonly DroppedFieldLogger $droppedFieldLogger
    ) {
    }

    /**
     * Add `facet_by` for every requested term aggregation field to the search params.
     *
     * Dynamic (price) buckets are skipped: their ranges are computed by the price algorithm from the
     * match set ({@see self::buildDynamicValues()}), never read from `facet_counts`, so faceting them
     * would request up to {@see self::MAX_FACET_VALUES} distinct price counts per search for nothing —
     * and the scoped price field is resolved per-context by the algorithm's data provider, not here.
     * Term-bucket fields map 1:1 to their document field, so no name resolution is needed.
     * With a known schema, a field it does not mark `facet` is skipped (Typesense would 404); its
     * bucket stays empty in {@see self::build()}.
     *
     * @param array<string,mixed> $params Typesense search params from the query builder
     * @param RequestInterface $request
     * @param CollectionSchema|null $schema
     * @return array<string,mixed>
     */
    public function addAggregations(array $params, RequestInterface $request, ?CollectionSchema $schema = null): array
    {
        $fields = [];
        foreach ($request->getAggregation() ?? [] as $bucket) {
            if ($bucket->getType() === BucketInterface::TYPE_DYNAMIC) {
                continue;
            }
            $field = $bucket->getField();
            if ($field === '' || isset($fields[$field])) {
                continue;
            }
            if ($schema !== null && !$schema->isFacet($field)) {
                $this->droppedFieldLogger->log(
                    $schema->getAlias(),
                    $field,
                    sprintf(
                        'Skipped Typesense facet on field "%s": not a facet field in alias "%s".',
                        $field,
                        $schema->getAlias()
                    )
                );
                continue;
            }
            $fields[$field] = true;
        }

        if ($fields === []) {
            return $params;
        }

        $params['facet_by'] = implode(',', array_keys($fields));
        $params['max_facet_values'] = self::MAX_FACET_VALUES;

        return $params;
    }

    /**
     * Map a raw Typesense response into a Magento aggregation.
     *
     * Term facets are read straight from the response `facet_counts` (whole match set). Dynamic price
     * buckets are computed over the same `$matchQuery` the main search ran (not the returned page's
     * ids); an empty `$matchQuery` marks a degraded/unsupported request, so the price bucket is empty.
     *
     * @param RequestInterface $request
     * @param array<string,mixed> $rawResponse decoded Typesense search response
     * @param array<string,mixed> $matchQuery match-set params (`q`/`query_by`/`query_by_weights`/`filter_by`)
     * @param int $storeId resolved store id of the request
     * @param CollectionSchema|null $schema types term values by field; null keeps them strings
     */
    public function build(
        RequestInterface $request,
        array $rawResponse,
        array $matchQuery = [],
        int $storeId = 0,
        ?CollectionSchema $schema = null
    ): Aggregation {
        $facets = $this->indexFacets($rawResponse['facet_counts'] ?? []);
        $matchedIds = $this->matchedIds($rawResponse['hits'] ?? []);

        $buckets = [];
        foreach ($request->getAggregation() ?? [] as $bucket) {
            if ($bucket->getType() === BucketInterface::TYPE_DYNAMIC) {
                $values = $matchQuery === []
                    ? []
                    : $this->buildDynamicValues($bucket, $request->getDimensions(), $matchQuery, $storeId, $matchedIds);
            } else {
                $field = $bucket->getField();
                $values = $this->buildTermValues($facets[$field] ?? null, $schema?->getType($field));
            }
            $buckets[$bucket->getName()] = new Bucket($bucket->getName(), $values);
        }

        return new Aggregation($buckets);
    }

    /**
     * Document ids of the returned page, used only to seed the price algorithm's `EntityStorage`.
     *
     * The framework `Auto` algorithm gates its whole computation on a non-empty `EntityStorage`
     * ({@see \Magento\Framework\Search\Dynamic\Algorithm\Auto::getItems()}), so an empty storage
     * yields no price ranges. Mirroring Magento's ES `Aggregation\Builder\Dynamic`, we seed it from
     * the page's hit ids; our data provider never reads them (stats span the whole match set).
     *
     * @param array<int,array<string,mixed>> $hits
     * @return array<int,string>
     */
    private function matchedIds(array $hits): array
    {
        $ids = [];
        foreach ($hits as $hit) {
            $id = $hit['document'][self::DOCUMENT_ID_FIELD] ?? null;
            if ($id !== null) {
                $ids[] = (string)$id;
            }
        }

        return $ids;
    }

    /**
     * Index Typesense `facet_counts` by field name.
     *
     * @param array<int,array<string,mixed>> $facetCounts
     * @return array<string,array<string,mixed>>
     */
    private function indexFacets(array $facetCounts): array
    {
        $map = [];
        foreach ($facetCounts as $facet) {
            $name = $facet['field_name'] ?? null;
            if (is_string($name)) {
                $map[$name] = $facet;
            }
        }

        return $map;
    }

    /**
     * Term facet → one aggregation value per distinct value, typed by the field's schema type.
     *
     * @param array<string,mixed>|null $facet
     * @param string|null $type Typesense field type; null or non-numeric keeps string values
     * @return Value[]
     */
    private function buildTermValues(?array $facet, ?string $type): array
    {
        $values = [];
        foreach ($facet['counts'] ?? [] as $count) {
            $value = $count['value'] ?? null;
            if ($value === null) {
                continue;
            }
            $value = $this->typeValue($value, $type);
            $values[] = new Value($value, ['value' => $value, 'count' => (int)($count['count'] ?? 0)]);
        }

        return $values;
    }

    /**
     * Int fields → `int` (GraphQL `Category` builder compares strictly), legacy `bool` → `1`/`0`.
     *
     * @param mixed $value raw facet value (Typesense returns strings)
     * @param string|null $type
     * @return int|string
     */
    private function typeValue(mixed $value, ?string $type): int|string
    {
        return match ($type) {
            'int32', 'int64', 'int32[]', 'int64[]' => (int)$value,
            'bool' => in_array(strtolower((string)$value), ['true', '1'], true) ? 1 : 0,
            default => (string)$value,
        };
    }

    /**
     * Dynamic price bucket → ranges computed by the configured price algorithm.
     *
     * Runs `Auto`/`Improved`/`Manual` (`$bucket->getMethod()`) over the match set through the
     * framework algorithm repository and our engine-resolved data provider, then maps the
     * `{from, to, count}` items into `from_to`-keyed values — the shape `Filter\Price` reads.
     * The match-set query is bound onto the data provider ({@see DataProvider::setMatchContext()})
     * so stats span the whole result set, not the returned page. The `EntityStorage` is seeded with
     * the page's `$matchedIds` purely to clear the `Auto` algorithm's empty-storage gate — its data
     * provider ignores those ids. An unknown method or algorithm error degrades to an empty bucket.
     *
     * @param BucketInterface $bucket
     * @param \Magento\Framework\Search\Request\Dimension[] $dimensions
     * @param array<string,mixed> $matchQuery match-set params the price stats are computed over
     * @param int $storeId resolved store id whose collection alias is queried
     * @param array<int,string> $matchedIds page hit ids seeding the algorithm's `EntityStorage`
     * @return Value[]
     */
    private function buildDynamicValues(
        BucketInterface $bucket,
        array $dimensions,
        array $matchQuery,
        int $storeId,
        array $matchedIds
    ): array {
        try {
            $dataProvider = $this->dataProviderFactory->create();
            if ($dataProvider instanceof DataProvider) {
                $dataProvider->setMatchContext($storeId, $matchQuery);
            }
            $algorithm = $this->algorithmRepository->get(
                $bucket->getMethod(),
                ['dataProvider' => $dataProvider]
            );
            $items = $algorithm->getItems($bucket, $dimensions, $this->entityStorageFactory->create($matchedIds));
        } catch (LocalizedException $e) {
            return [];
        }

        $values = [];
        foreach ($items as $item) {
            $from = $item['from'] ?? '';
            $to = $item['to'] ?? '';
            $key = $from . '_' . $to;
            $values[] = new Value(
                $key,
                ['from' => $from, 'to' => $to, 'count' => (int)($item['count'] ?? 0), 'value' => $key]
            );
        }

        return $values;
    }
}
