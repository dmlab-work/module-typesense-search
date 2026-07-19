<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseSearch\Model\DataProvider;

use MageDevGroup\TypesenseCore\Exception\TypesenseException;
use MageDevGroup\TypesenseCore\Model\Client\TypesenseClient;
use MageDevGroup\TypesenseIndexer\Api\SearchableFieldsProviderInterface;
use MageDevGroup\TypesenseIndexer\Api\EngineCode;
use MageDevGroup\TypesenseIndexer\Api\IndexNameResolverInterface;
use Magento\AdvancedSearch\Model\SuggestedQueriesInterface;
use Magento\CatalogSearch\Model\Indexer\Fulltext;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Search\EngineResolverInterface;
use Magento\Search\Model\QueryInterface;
use Magento\Search\Model\QueryResult;
use Magento\Search\Model\QueryResultFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Search suggestions backed by Typesense's typo-tolerant search.
 *
 * Magento's ES {@see \Magento\Elasticsearch\Model\DataProvider\Base\Suggestions} builds a phrase
 * suggester query — an ES-only construct Typesense has no equivalent for. Typesense instead surfaces
 * suggestions through typo tolerance on a normal search: the top typo-corrected hits' searchable
 * text *are* the suggested queries. This provider runs one such search against the store's collection
 * **alias** (owned by `typesense-indexer` — never rebuilt here), reads the configurable suggestion
 * field off each hit, dedupes and caps by `catalog/search/search_suggestion_count`, and — when
 * `search_suggestion_count_results_enabled` is on — attaches each suggestion's own hit count.
 *
 * Registered into {@see \Magento\AdvancedSearch\Model\SuggestedQueries::data} under `typesense`;
 * leaving the engine unregistered in that pool is not an option (the feature would fatal).
 *
 * Honest gating: suggestions are produced only when Typesense is the active engine and
 * `catalog/search/search_suggestion_enabled` is set. Any Typesense failure degrades to no items
 * (logged) so the storefront search box never fatals.
 */
class Suggestions implements SuggestedQueriesInterface
{
    /**
     * Document field whose value becomes the suggested query text; product name by default.
     */
    private const DEFAULT_SUGGESTION_FIELD = 'name';

    /**
     * @param ScopeConfigInterface $scopeConfig native suggestion config paths
     * @param EngineResolverInterface $engineResolver the currently configured search engine
     * @param QueryResultFactory $queryResultFactory builds the {@see QueryResult} items
     * @param TypesenseClient $client core's sole Typesense REST client
     * @param SearchableFieldsProviderInterface $searchableFieldsProvider indexer-owned `query_by` seam
     * @param IndexNameResolverInterface $indexNameResolver indexer-owned alias-name authority
     * @param StoreManagerInterface $storeManager resolves the storefront store scope
     * @param LoggerInterface $logger
     * @param string $suggestionField document field read off each hit for the suggestion text
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EngineResolverInterface $engineResolver,
        private readonly QueryResultFactory $queryResultFactory,
        private readonly TypesenseClient $client,
        private readonly SearchableFieldsProviderInterface $searchableFieldsProvider,
        private readonly IndexNameResolverInterface $indexNameResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
        private readonly string $suggestionField = self::DEFAULT_SUGGESTION_FIELD
    ) {
    }

    /**
     * @inheritDoc
     *
     * @return QueryResult[]
     */
    public function getItems(QueryInterface $query)
    {
        if (!$this->isSuggestionsAllowed()) {
            return [];
        }

        $storeId = (int)$this->storeManager->getStore()->getId();
        $queryBy = array_keys($this->searchableFieldsProvider->get($storeId));
        if ($queryBy === []) {
            return [];
        }

        $limit = $this->getSearchSuggestionsCount();
        if ($limit <= 0) {
            return [];
        }

        $countResults = $this->isResultsCountEnabled();
        $alias = $this->indexNameResolver->getAliasName(Fulltext::INDEXER_ID, $storeId);
        $queryBy = implode(',', $queryBy);

        $hits = $this->search($alias, (string)$query->getQueryText(), $queryBy, $limit);

        $items = [];
        $seen = [];
        foreach ($hits as $hit) {
            $text = $hit['document'][$this->suggestionField] ?? null;
            if (!is_scalar($text)) {
                continue;
            }
            $text = trim((string)$text);
            if ($text === '' || isset($seen[$text])) {
                continue;
            }
            $seen[$text] = true;

            $items[] = $this->queryResultFactory->create(
                [
                    'queryText' => $text,
                    'resultsCount' => $countResults ? $this->frequency($alias, $text, $queryBy) : null,
                ]
            );

            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * @inheritDoc
     */
    public function isResultsCountEnabled()
    {
        return $this->scopeConfig->isSetFlag(
            SuggestedQueriesInterface::SEARCH_SUGGESTION_COUNT_RESULTS_ENABLED,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Suggestions run only when Typesense is active and the native flag is set.
     */
    private function isSuggestionsAllowed(): bool
    {
        return $this->engineResolver->getCurrentSearchEngine() === EngineCode::ENGINE
            && $this->scopeConfig->isSetFlag(
                SuggestedQueriesInterface::SEARCH_SUGGESTION_ENABLED,
                ScopeInterface::SCOPE_STORE
            );
    }

    /**
     * Configured maximum number of suggestions to return.
     */
    private function getSearchSuggestionsCount(): int
    {
        return (int)$this->scopeConfig->getValue(
            SuggestedQueriesInterface::SEARCH_SUGGESTION_COUNT,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Run the typo-tolerant search against the alias and return its hits, empty on failure.
     *
     * @param string $alias collection alias to query
     * @param string $queryText raw user query
     * @param string $queryBy comma-joined searchable fields
     * @param int $limit
     * @return array<int,array<string,mixed>>
     */
    private function search(string $alias, string $queryText, string $queryBy, int $limit): array
    {
        $params = [
            'q' => $queryText === '' ? '*' : $queryText,
            'query_by' => $queryBy,
            'per_page' => $limit,
            'page' => 1,
        ];

        try {
            $response = $this->client->request(
                'GET',
                '/collections/' . $alias . '/documents/search',
                null,
                $params
            );
        } catch (TypesenseException $e) {
            $this->logger->critical($e);

            return [];
        }

        return $response['hits'] ?? [];
    }

    /**
     * Hit count for a single suggestion, used as its results count; 0 on failure.
     *
     * @param string $alias
     * @param string $text suggestion text to count matches for
     * @param string $queryBy comma-joined searchable fields
     */
    private function frequency(string $alias, string $text, string $queryBy): int
    {
        $params = [
            'q' => $text,
            'query_by' => $queryBy,
            'per_page' => 0,
        ];

        try {
            $response = $this->client->request(
                'GET',
                '/collections/' . $alias . '/documents/search',
                null,
                $params
            );
        } catch (TypesenseException $e) {
            $this->logger->critical($e);

            return 0;
        }

        return (int)($response['found'] ?? 0);
    }
}
