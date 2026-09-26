<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\SearchAdapter\Aggregation;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Client\TypesenseClient;
use DmLab\TypesenseIndexer\Api\IndexNameResolverInterface;
use Magento\CatalogSearch\Model\Indexer\Fulltext;
use Magento\Framework\Search\Dynamic\IntervalInterface;
use Psr\Log\LoggerInterface;

/**
 * Price interval over a Typesense collection, driving the layered-navigation price slider.
 *
 * The "improved" price algorithm asks an interval to walk the sorted price axis of the current
 * result set — {@see self::load()} for a window, {@see self::loadPrevious()} / {@see self::loadNext()}
 * to slide it. Each call is a price-sorted Typesense search constrained to the **match set** (the same
 * `q` / `query_by` / `filter_by` the main search ran, {@see \DmLab\TypesenseSearch\SearchAdapter\
 * Dynamic\DataProvider}), reading back only the `price` field. Constraining to the match-set query
 * rather than the returned page's ids is what keeps the slider consistent with ES over a paginated
 * result set. The Typesense `offset`/`limit` and `:>=` / `:<` filter operators replace ES's
 * `from`/`size` and range clauses.
 *
 * Built per request by the framework `IntervalFactory` (registered into `IntervalFactory::intervals`
 * under `typesense`); `fieldName`, `storeId` and `matchQuery` are the per-request data, the client +
 * resolver come from DI.
 */
class Interval implements IntervalInterface
{
    /**
     * Smallest price delta, matching Magento's ES interval so boundary rounding lines up.
     */
    private const DELTA = 0.005;

    /**
     * Typesense's hard cap on hits per search request; larger windows are read in chunks.
     */
    private const MAX_LIMIT = 250;

    /**
     * @param TypesenseClient $client core's sole Typesense REST client
     * @param IndexNameResolverInterface $indexNameResolver indexer-owned alias-name authority
     * @param LoggerInterface $logger
     * @param string $fieldName numeric field the interval walks (price)
     * @param int $storeId store view whose collection is queried
     * @param array<string,mixed> $matchQuery match-set params (`q`/`query_by`/`query_by_weights`/`filter_by`)
     */
    public function __construct(
        private readonly TypesenseClient $client,
        private readonly IndexNameResolverInterface $indexNameResolver,
        private readonly LoggerInterface $logger,
        private readonly string $fieldName,
        private readonly int $storeId,
        private readonly array $matchQuery
    ) {
    }

    /**
     * @inheritDoc
     */
    public function load($limit, $offset = null, $lower = null, $upper = null)
    {
        [$from, $to] = $this->bounds($lower, $upper);

        return $this->queryPrices($from, $to, (int)$limit, (int)$offset);
    }

    /**
     * @inheritDoc
     */
    public function loadPrevious($data, $index, $lower = null)
    {
        $from = $lower ? $lower - self::DELTA : null;
        $to = $data ? $data - self::DELTA : null;
        if ($lower === null && (float)$data === 0.0) {
            $from = 0.0;
            $to = 0.0;
        }

        $offset = $this->count($from, $to);
        if ($offset <= 0) {
            return false;
        }

        return $this->load($index - $offset + 1, $offset - 1, $lower);
    }

    /**
     * @inheritDoc
     */
    public function loadNext($data, $rightIndex, $upper = null)
    {
        $offset = $this->count($data + self::DELTA, null);
        if ($offset <= 0) {
            return false;
        }

        $prices = $this->load($rightIndex - $offset + 1, $offset - 1, null, $upper);

        return array_reverse($prices);
    }

    /**
     * Translate lower/upper request bounds into Typesense `>=` / `<` price bounds.
     *
     * A pair of nulls maps to the empty range `[0, 0)` — mirrors the ES interval, which returns
     * nothing when neither bound is set.
     *
     * @param int|float|null $lower
     * @param int|float|null $upper
     * @return array{0: float|null, 1: float|null}
     */
    private function bounds($lower, $upper): array
    {
        if ($lower === null && $upper === null) {
            return [0.0, 0.0];
        }

        return [
            $lower !== null ? $lower - self::DELTA : null,
            $upper !== null ? $upper - self::DELTA : null,
        ];
    }

    /**
     * Prices for a sorted window of the constrained result set.
     *
     * @param float|null $from inclusive lower price bound
     * @param float|null $to exclusive upper price bound
     * @param int $limit
     * @param int $offset
     * @return float[]
     */
    private function queryPrices(?float $from, ?float $to, int $limit, int $offset): array
    {
        $limit = max(0, $limit);
        $offset = max(0, $offset);
        $filterBy = $this->filter($from, $to);
        $alias = $this->alias();

        $prices = [];
        $fetched = 0;
        while ($fetched < $limit) {
            $chunk = min(self::MAX_LIMIT, $limit - $fetched);

            try {
                $response = $this->client->request(
                    'GET',
                    '/collections/' . $alias . '/documents/search',
                    null,
                    $this->baseParams() + [
                        'filter_by' => $filterBy,
                        'sort_by' => $this->fieldName . ':asc',
                        'include_fields' => $this->fieldName,
                        'limit' => $chunk,
                        'offset' => $offset + $fetched,
                    ]
                );
            } catch (TypesenseException $e) {
                $this->logger->critical($e);

                return [];
            }

            $hits = $response['hits'] ?? [];
            foreach ($hits as $hit) {
                if (isset($hit['document'][$this->fieldName])) {
                    $prices[] = (float)$hit['document'][$this->fieldName];
                }
            }
            if (count($hits) < $chunk) {
                break;
            }
            $fetched += count($hits);
        }

        return $prices;
    }

    /**
     * Number of documents in the constrained result set within a price range.
     *
     * @param float|null $from
     * @param float|null $to
     */
    private function count(?float $from, ?float $to): int
    {
        try {
            $response = $this->client->request(
                'GET',
                '/collections/' . $this->alias() . '/documents/search',
                null,
                $this->baseParams() + [
                    'filter_by' => $this->filter($from, $to),
                    'per_page' => 0,
                ]
            );
        } catch (TypesenseException $e) {
            $this->logger->critical($e);

            return 0;
        }

        return (int)($response['found'] ?? 0);
    }

    /**
     * The match-set query params (`q`/`query_by`/`query_by_weights`) that scope every window, minus
     * `filter_by` — the price bounds are ANDed onto that separately in {@see self::filter()}.
     *
     * @return array<string,mixed>
     */
    private function baseParams(): array
    {
        $params = [];
        foreach (['q', 'query_by', 'query_by_weights'] as $key) {
            if (isset($this->matchQuery[$key]) && $this->matchQuery[$key] !== '') {
                $params[$key] = $this->matchQuery[$key];
            }
        }
        if (!isset($params['q'])) {
            $params['q'] = '*';
        }

        return $params;
    }

    /**
     * Build the `filter_by` expression: match-set filter ANDed with the price bounds.
     *
     * @param float|null $from inclusive lower bound
     * @param float|null $to exclusive upper bound
     */
    private function filter(?float $from, ?float $to): string
    {
        $parts = [];
        $matchFilter = $this->matchQuery['filter_by'] ?? '';
        if ($matchFilter !== '') {
            $parts[] = '(' . $matchFilter . ')';
        }
        if ($from !== null) {
            $parts[] = $this->fieldName . ':>=' . $from;
        }
        if ($to !== null) {
            $parts[] = $this->fieldName . ':<' . $to;
        }

        return implode(' && ', $parts);
    }

    /**
     * The store's collection alias.
     */
    private function alias(): string
    {
        return $this->indexNameResolver->getAliasName(Fulltext::INDEXER_ID, $this->storeId);
    }
}
