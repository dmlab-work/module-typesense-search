<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\SearchAdapter\Dynamic;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Client\TypesenseClient;
use DmLab\TypesenseIndexer\Api\IndexNameResolverInterface;
use DmLab\TypesenseSearch\SearchAdapter\Field\FieldNameResolver;
use Magento\Catalog\Model\Layer\Filter\Price\Range;
use Magento\CatalogSearch\Model\Indexer\Fulltext;
use Magento\Framework\Search\Dynamic\DataProviderInterface;
use Magento\Framework\Search\Dynamic\EntityStorage;
use Magento\Framework\Search\Dynamic\IntervalFactory;
use Magento\Framework\Search\Request\BucketInterface;
use Psr\Log\LoggerInterface;

/**
 * Typesense price statistics + histogram provider for Magento's dynamic price algorithm.
 *
 * Magento's own {@see \Magento\Elasticsearch\SearchAdapter\Dynamic\DataProvider} is ES-only by
 * its own docblock (imports the ES `QueryContainer`), so the price-slider algorithm cannot use it.
 * This is the Typesense equivalent: every method queries the store's collection **alias** (owned by
 * `typesense-indexer` — never rebuilt here) constrained to the **whole match set**, reading
 * Typesense numeric **facet stats** and per-value counts.
 *
 * Match set, not page: like ES's `QueryContainer`, the price statistics are computed over the same
 * query the main search ran (`q` / `query_by` / `filter_by`), **not** over the ids of the returned
 * page. Magento asks the engine for one page of ids at a time ({@see \Magento\CatalogSearch\Model\
 * ResourceModel\Fulltext\Collection} sets `size = getPageSize()`), so constraining price stats to the
 * returned ids would compute the slider range over ~24 products instead of the full result set. The
 * match-set query is handed in via {@see self::setMatchContext()} before the algorithm runs.
 *
 * Registered into `Magento\Framework\Search\Dynamic\DataProviderFactory::dataProviders` under
 * `typesense`; the framework algorithm ({@see \Magento\Framework\Search\Dynamic\Algorithm\Auto})
 * calls it to compute price ranges and drives the price slider through {@see Interval}.
 *
 * A Typesense failure degrades to zeroed stats / empty histogram (logged) so the slider never fatals.
 */
class DataProvider implements DataProviderInterface
{
    /**
     * Attribute code aggregated for the price slider; resolved to its scoped document field
     * (`price_<customerGroupId>_<websiteId>`) via {@see FieldNameResolver} before every query.
     */
    private const DEFAULT_AGGREGATION_FIELD = 'price';

    /**
     * Upper bound on distinct price values Typesense returns per histogram query.
     */
    private const MAX_FACET_VALUES = 10000;

    /**
     * Match-set query params (`q`, `query_by`, `query_by_weights`, `filter_by`) the price stats are
     * computed over, set per request by {@see self::setMatchContext()}. Null until set — a query is
     * never issued without it, so price stats can never leak over the whole catalog.
     *
     * @var array<string,mixed>|null
     */
    private ?array $matchQuery = null;

    /**
     * Store id whose collection alias the match-set query runs against.
     */
    private int $storeId = 0;

    /**
     * @param TypesenseClient $client core's sole Typesense REST client
     * @param IndexNameResolverInterface $indexNameResolver indexer-owned alias-name authority
     * @param Range $range configured layered-navigation price range step
     * @param IntervalFactory $intervalFactory engine-resolved interval factory (builds our {@see Interval})
     * @param FieldNameResolver $fieldNameResolver maps the price attribute to its scoped document field
     * @param LoggerInterface $logger
     * @param string $aggregationFieldName price attribute code to aggregate (defaults to `price`)
     */
    public function __construct(
        private readonly TypesenseClient $client,
        private readonly IndexNameResolverInterface $indexNameResolver,
        private readonly Range $range,
        private readonly IntervalFactory $intervalFactory,
        private readonly FieldNameResolver $fieldNameResolver,
        private readonly LoggerInterface $logger,
        private readonly string $aggregationFieldName = self::DEFAULT_AGGREGATION_FIELD
    ) {
    }

    /**
     * Bind the match-set query the price statistics must be computed over.
     *
     * Called by {@see \DmLab\TypesenseSearch\SearchAdapter\Aggregation\Builder} once per
     * request before the price algorithm runs — the Typesense analogue of the ES adapter setting a
     * `QueryContainer` on its aggregation builder.
     *
     * @param int $storeId resolved store id of the request (its collection alias is queried)
     * @param array<string,mixed> $matchQuery `q` / `query_by` / `query_by_weights` / `filter_by`
     */
    public function setMatchContext(int $storeId, array $matchQuery): void
    {
        $this->storeId = $storeId;
        $this->matchQuery = $matchQuery;
    }

    /**
     * @inheritDoc
     */
    public function getRange()
    {
        return $this->range->getPriceRange();
    }

    /**
     * @inheritDoc
     */
    public function getAggregations(EntityStorage $entityStorage)
    {
        $response = $this->facetSearch($this->fieldNameResolver->resolve($this->aggregationFieldName));
        $stats = $response['facet_counts'][0]['stats'] ?? [];

        return [
            // Matching-document count, not distinct-value count: Magento's "equalize product
            // counts" price algorithm expects documents, mirroring ES extended_stats.count.
            'count' => (int)($response['found'] ?? 0),
            'max' => (float)($stats['max'] ?? 0),
            'min' => (float)($stats['min'] ?? 0),
            // Typesense reports no standard deviation; the Auto algorithm relies only on max.
            'std' => 0,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getInterval(
        BucketInterface $bucket,
        array $dimensions,
        EntityStorage $entityStorage
    ) {
        return $this->intervalFactory->create(
            [
                'fieldName' => $this->fieldNameResolver->resolve($bucket->getField()),
                'storeId' => $this->storeId,
                'matchQuery' => $this->matchQuery ?? [],
            ]
        );
    }

    /**
     * @inheritDoc
     */
    public function getAggregation(
        BucketInterface $bucket,
        array $dimensions,
        $range,
        EntityStorage $entityStorage
    ) {
        $counts = $this->facetCounts($this->fieldNameResolver->resolve($bucket->getField()));

        $result = [];
        $range = (float)$range;
        if ($range <= 0) {
            return $result;
        }

        foreach ($counts as $count) {
            $key = (int)floor((float)($count['value'] ?? 0) / $range) + 1;
            $result[$key] = ($result[$key] ?? 0) + (int)($count['count'] ?? 0);
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function prepareData($range, array $dbRanges)
    {
        $data = [];
        foreach ($dbRanges as $index => $count) {
            $fromPrice = $index == 1 ? 0 : ($index - 1) * $range;
            $toPrice = $index * $range;
            $data[] = [
                'from' => $fromPrice,
                'to' => $toPrice,
                'count' => $count,
            ];
        }

        return $data;
    }

    /**
     * Per-value facet counts for a field over the match set.
     *
     * @param string $field
     * @return array<int,array<string,mixed>>
     */
    private function facetCounts(string $field): array
    {
        $response = $this->facetSearch($field);

        return $response['facet_counts'][0]['counts'] ?? [];
    }

    /**
     * Run a facet-only search (no hits) over the match set, degrading to empty on failure.
     *
     * @param string $field
     * @return array<string,mixed>
     */
    private function facetSearch(string $field): array
    {
        if ($this->matchQuery === null) {
            return [];
        }

        $alias = $this->indexNameResolver->getAliasName(Fulltext::INDEXER_ID, $this->storeId);

        $params = $this->matchQuery;
        $params['facet_by'] = $field;
        $params['max_facet_values'] = self::MAX_FACET_VALUES;
        $params['per_page'] = 0;

        try {
            return $this->client->request('GET', '/collections/' . $alias . '/documents/search', null, $params);
        } catch (TypesenseException $e) {
            $this->logger->critical($e);

            return [];
        }
    }
}
