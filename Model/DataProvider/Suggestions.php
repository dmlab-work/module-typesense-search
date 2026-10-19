<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Model\DataProvider;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Client\TypesenseClient;
use DmLab\TypesenseIndexer\Api\SearchableFieldsProviderInterface;
use DmLab\TypesenseIndexer\Api\EngineCode;
use DmLab\TypesenseIndexer\Api\IndexNameResolverInterface;
use DmLab\TypesenseSearch\SearchAdapter\Schema\SchemaReader;
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
 * "Did you mean" backed by Typesense's typo-tolerant search.
 *
 * ES uses a phrase suggester Typesense lacks; instead one search runs against the store alias and the
 * hits' `matched_tokens` rebuild a corrected query. Returns at most one item, only for a changed query.
 * Gated on the active engine and `search_suggestion_enabled`; Typesense failures degrade to no items.
 */
class Suggestions implements SuggestedQueriesInterface
{
    /**
     * Hits scanned for matched tokens.
     */
    private const HITS_PER_PAGE = 10;

    /**
     * Storefront-visible products only: "Search" and "Catalog, Search".
     */
    private const VISIBILITY_FILTER = 'visibility:=[3,4]';

    /**
     * Largest edit distance between a query token and a matched token.
     */
    private const MAX_DISTANCE = 2;

    /**
     * @param ScopeConfigInterface $scopeConfig native suggestion config paths
     * @param EngineResolverInterface $engineResolver the currently configured search engine
     * @param QueryResultFactory $queryResultFactory builds the {@see QueryResult} items
     * @param TypesenseClient $client core's sole Typesense REST client
     * @param SearchableFieldsProviderInterface $searchableFieldsProvider indexer-owned `query_by` seam
     * @param IndexNameResolverInterface $indexNameResolver indexer-owned alias-name authority
     * @param StoreManagerInterface $storeManager resolves the storefront store scope
     * @param SchemaReader $schemaReader drops fields the alias collection does not declare yet
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EngineResolverInterface $engineResolver,
        private readonly QueryResultFactory $queryResultFactory,
        private readonly TypesenseClient $client,
        private readonly SearchableFieldsProviderInterface $searchableFieldsProvider,
        private readonly IndexNameResolverInterface $indexNameResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly SchemaReader $schemaReader,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     *
     * @return QueryResult[]
     */
    public function getItems(QueryInterface $query)
    {
        if (!$this->isSuggestionsAllowed() || $this->getSearchSuggestionsCount() <= 0) {
            return [];
        }

        $queryText = trim((string)$query->getQueryText());
        $tokens = $this->tokenize($queryText);
        if ($tokens === []) {
            return [];
        }

        $storeId = (int)$this->storeManager->getStore()->getId();
        $alias = $this->indexNameResolver->getAliasName(Fulltext::INDEXER_ID, $storeId);
        $fields = array_keys($this->searchableFieldsProvider->get($storeId));
        // Typesense 404s on an undeclared field (e.g. `<code>_value` before the collection is reindexed).
        $schema = $this->schemaReader->get($alias);
        if ($schema !== null) {
            $fields = array_filter($fields, static fn ($field): bool => $schema->has((string)$field));
        }
        $fields = implode(',', $fields);
        if ($fields === '') {
            return [];
        }

        $path = '/collections/' . $alias . '/documents/search';

        $response = $this->search($path, [
            'q' => $queryText,
            'query_by' => $fields,
            'include_fields' => $fields,
            'filter_by' => self::VISIBILITY_FILTER,
            'per_page' => self::HITS_PER_PAGE,
            'page' => 1,
        ]);
        if ($response === null) {
            return [];
        }

        $correction = $this->correct($tokens, $this->matchedTokens($response['hits'] ?? []));
        if ($correction === null) {
            return [];
        }

        $count = null;
        if ($this->isResultsCountEnabled()) {
            $countResponse = $this->search($path, [
                'q' => $correction,
                'query_by' => $fields,
                'filter_by' => self::VISIBILITY_FILTER,
                'per_page' => 0,
            ]);
            $count = (int)($countResponse['found'] ?? 0);
        }

        return [$this->queryResultFactory->create(['queryText' => $correction, 'resultsCount' => $count])];
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
     * Configured maximum number of suggestions; 0 disables them.
     */
    private function getSearchSuggestionsCount(): int
    {
        return (int)$this->scopeConfig->getValue(
            SuggestedQueriesInterface::SEARCH_SUGGESTION_COUNT,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Lowercased whitespace-separated query tokens.
     *
     * @param string $text
     * @return string[]
     */
    private function tokenize(string $text): array
    {
        return preg_split('/\s+/u', mb_strtolower(trim($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Ordered unique lowercased `matched_tokens` across every hit's highlights.
     *
     * Array fields report `matched_tokens` per element (a list of lists), so one level is flattened.
     *
     * @param array<int,array<string,mixed>> $hits
     * @return string[]
     */
    private function matchedTokens(array $hits): array
    {
        $matched = [];
        foreach ($hits as $hit) {
            foreach ($hit['highlights'] ?? [] as $highlight) {
                foreach ($highlight['matched_tokens'] ?? [] as $entry) {
                    foreach (is_array($entry) ? $entry : [$entry] as $token) {
                        if (is_string($token) && $token !== '') {
                            $matched[mb_strtolower($token)] = true;
                        }
                    }
                }
            }
        }

        return array_map('strval', array_keys($matched));
    }

    /**
     * Rebuild the query from matched tokens; null when nothing is left or nothing changed.
     *
     * @param string[] $tokens normalized query tokens
     * @param string[] $matched
     */
    private function correct(array $tokens, array $matched): ?string
    {
        $corrected = [];
        foreach ($tokens as $token) {
            if (in_array($token, $matched, true)) {
                $corrected[] = $token;
                continue;
            }
            $best = null;
            $bestDistance = self::MAX_DISTANCE + 1;
            foreach ($matched as $candidate) {
                $distance = $this->distance($token, $candidate);
                if ($distance < $bestDistance) {
                    $best = $candidate;
                    $bestDistance = $distance;
                }
            }
            if ($best !== null) {
                $corrected[] = $best;
            }
        }

        $candidate = implode(' ', $corrected);

        return $candidate === '' || $candidate === implode(' ', $tokens) ? null : $candidate;
    }

    /**
     * Run a search against the alias; null on failure (logged).
     *
     * @param string $path search endpoint of the store alias
     * @param array<string,mixed> $params
     * @return array<string,mixed>|null
     */
    private function search(string $path, array $params): ?array
    {
        try {
            return $this->client->request('GET', $path, null, $params);
        } catch (TypesenseException $e) {
            $this->logger->critical($e);

            return null;
        }
    }

    /**
     * Levenshtein distance in characters; PHP's `levenshtein()` counts bytes, inflating non-ASCII typos.
     *
     * @param string $a
     * @param string $b
     */
    private function distance(string $a, string $b): int
    {
        $left = mb_str_split($a);
        $right = mb_str_split($b);
        $previous = range(0, count($right));
        foreach ($left as $i => $leftChar) {
            $current = [$i + 1];
            foreach ($right as $j => $rightChar) {
                $current[] = min(
                    $previous[$j + 1] + 1,
                    $current[$j] + 1,
                    $previous[$j] + ($leftChar === $rightChar ? 0 : 1)
                );
            }
            $previous = $current;
        }

        return $previous[count($right)];
    }
}
