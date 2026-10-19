<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Test\Unit\Model\DataProvider;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Client\TypesenseClient;
use DmLab\TypesenseIndexer\Api\SearchableFieldsProviderInterface;
use DmLab\TypesenseIndexer\Api\EngineCode;
use DmLab\TypesenseIndexer\Api\IndexNameResolverInterface;
use DmLab\TypesenseSearch\Model\DataProvider\Suggestions;
use DmLab\TypesenseSearch\SearchAdapter\Schema\CollectionSchema;
use DmLab\TypesenseSearch\SearchAdapter\Schema\SchemaReader;
use Magento\AdvancedSearch\Model\SuggestedQueriesInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Search\EngineResolverInterface;
use Magento\Search\Model\QueryInterface;
use Magento\Search\Model\QueryResult;
use Magento\Search\Model\QueryResultFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class SuggestionsTest extends TestCase
{
    /** @var ScopeConfigInterface&MockObject */
    private $scopeConfig;
    /** @var EngineResolverInterface&MockObject */
    private $engineResolver;
    /** @var QueryResultFactory&MockObject */
    private $queryResultFactory;
    /** @var TypesenseClient&MockObject */
    private $client;
    /** @var SearchableFieldsProviderInterface&MockObject */
    private $searchableFields;
    /** @var IndexNameResolverInterface&MockObject */
    private $indexNameResolver;
    /** @var StoreManagerInterface&MockObject */
    private $storeManager;
    /** @var SchemaReader&MockObject */
    private $schemaReader;
    /** @var LoggerInterface&MockObject */
    private $logger;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->engineResolver = $this->createMock(EngineResolverInterface::class);
        $this->queryResultFactory = $this->createMock(QueryResultFactory::class);
        $this->client = $this->createMock(TypesenseClient::class);
        $this->searchableFields = $this->createMock(SearchableFieldsProviderInterface::class);
        $this->indexNameResolver = $this->createMock(IndexNameResolverInterface::class);
        $this->indexNameResolver->method('getAliasName')->willReturn('typesense_catalogsearch_fulltext_1');
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $this->storeManager->method('getStore')->willReturn($store);
        $this->schemaReader = $this->createMock(SchemaReader::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->searchableFields->method('get')->willReturn(['name' => 5, 'sku' => 3]);

        // Each factory->create() returns a QueryResult carrying the passed data so the test can assert it.
        $this->queryResultFactory->method('create')->willReturnCallback(
            static fn (array $data): QueryResult => new QueryResult($data['queryText'], $data['resultsCount'])
        );
    }

    private function suggestions(): Suggestions
    {
        return new Suggestions(
            $this->scopeConfig,
            $this->engineResolver,
            $this->queryResultFactory,
            $this->client,
            $this->searchableFields,
            $this->indexNameResolver,
            $this->storeManager,
            $this->schemaReader,
            $this->logger
        );
    }

    private function query(string $text): QueryInterface
    {
        $query = $this->createMock(QueryInterface::class);
        $query->method('getQueryText')->willReturn($text);

        return $query;
    }

    /**
     * Enable the engine + suggestion flag, set the count, and toggle results-count.
     */
    private function configure(bool $enabled, int $count, bool $countResults): void
    {
        $this->engineResolver->method('getCurrentSearchEngine')
            ->willReturn($enabled ? EngineCode::ENGINE : 'opensearch');

        $this->scopeConfig->method('isSetFlag')->willReturnMap([
            [SuggestedQueriesInterface::SEARCH_SUGGESTION_ENABLED, 'store', $enabled],
            [SuggestedQueriesInterface::SEARCH_SUGGESTION_COUNT_RESULTS_ENABLED, 'store', $countResults],
        ]);
        $this->scopeConfig->method('getValue')->willReturn((string)$count);
    }

    public function testDisabledEngineReturnsNoItems(): void
    {
        $this->engineResolver->method('getCurrentSearchEngine')->willReturn('opensearch');
        $this->scopeConfig->method('isSetFlag')->willReturn(true);
        $this->client->expects(self::never())->method('request');

        self::assertSame([], $this->suggestions()->getItems($this->query('shoes')));
    }

    public function testDisabledSuggestionFlagReturnsNoItems(): void
    {
        $this->engineResolver->method('getCurrentSearchEngine')->willReturn(EngineCode::ENGINE);
        $this->scopeConfig->method('isSetFlag')->willReturn(false);
        $this->client->expects(self::never())->method('request');

        self::assertSame([], $this->suggestions()->getItems($this->query('shoes')));
    }

    /**
     * A search response whose hits highlight the given tokens, one hit per token list.
     *
     * @param string[][] $perHit
     */
    private static function hits(array ...$perHit): array
    {
        return ['hits' => array_map(
            static fn (array $tokens): array => [
                'document' => ['name' => 'irrelevant'],
                'highlights' => [['field' => 'name', 'matched_tokens' => $tokens, 'snippet' => '']],
            ],
            $perHit
        )];
    }

    public function testTypoIsCorrectedFromMatchedTokens(): void
    {
        $this->configure(true, 5, false);
        $this->client->expects(self::once())->method('request')->willReturn(self::hits(['Jacket']));

        $items = $this->suggestions()->getItems($this->query('jcket'));

        self::assertCount(1, $items);
        self::assertSame('jacket', $items[0]->getQueryText());
        self::assertNull($items[0]->getResultsCount());
    }

    public function testUnmatchedTokenIsDropped(): void
    {
        $this->configure(true, 5, false);
        $this->client->method('request')->willReturn(self::hits(['Jacket']));

        $items = $this->suggestions()->getItems($this->query('red jcket'));

        self::assertCount(1, $items);
        self::assertSame('jacket', $items[0]->getQueryText());
    }

    public function testArrayFieldMatchedTokensCount(): void
    {
        $this->configure(true, 5, false);
        $this->client->method('request')->willReturn(['hits' => [[
            'document' => ['name' => 'irrelevant'],
            'highlights' => [
                ['field' => 'color_value', 'matched_tokens' => [['Blue']], 'snippets' => ['']],
                ['field' => 'name', 'matched_tokens' => ['Jacket'], 'snippet' => ''],
            ],
        ]]]);

        $items = $this->suggestions()->getItems($this->query('blue jcket'));

        self::assertSame('blue jacket', $items[0]->getQueryText());
    }

    public function testNonAsciiTypoIsMeasuredInCharacters(): void
    {
        $this->configure(true, 5, false);
        $this->client->method('request')->willReturn(self::hits(['Куртка']));

        $items = $this->suggestions()->getItems($this->query('крутка'));

        self::assertSame('куртка', $items[0]->getQueryText());
    }

    public function testMatchedTokensAreCollectedAcrossHits(): void
    {
        $this->configure(true, 5, false);
        $this->client->method('request')->willReturn(self::hits(['Red'], ['Jacket', 'red']));

        $items = $this->suggestions()->getItems($this->query('Red  jcket'));

        self::assertSame('red jacket', $items[0]->getQueryText());
    }

    public function testExactQueryReturnsNoItem(): void
    {
        $this->configure(true, 5, true);
        $this->client->expects(self::once())->method('request')->willReturn(self::hits(['Jacket']));

        self::assertSame([], $this->suggestions()->getItems($this->query('Jacket')));
    }

    public function testNoHitsReturnsNoItem(): void
    {
        $this->configure(true, 5, true);
        $this->client->expects(self::once())->method('request')->willReturn(['hits' => []]);

        self::assertSame([], $this->suggestions()->getItems($this->query('jcket')));
    }

    public function testEmptyQueryMakesNoRequest(): void
    {
        $this->configure(true, 5, false);
        $this->client->expects(self::never())->method('request');

        self::assertSame([], $this->suggestions()->getItems($this->query('   ')));
    }

    public function testSearchSendsVisibilityFilterAndIncludeFields(): void
    {
        $this->configure(true, 5, false);
        $this->client->expects(self::once())->method('request')->with(
            'GET',
            '/collections/typesense_catalogsearch_fulltext_1/documents/search',
            null,
            [
                'q' => 'jcket',
                'query_by' => 'name,sku',
                'include_fields' => 'name,sku',
                'filter_by' => 'visibility:=[3,4]',
                'per_page' => 10,
                'page' => 1,
            ]
        )->willReturn(['hits' => []]);

        self::assertSame([], $this->suggestions()->getItems($this->query('jcket')));
    }

    public function testFieldsMissingFromSchemaAreDropped(): void
    {
        $this->configure(true, 5, false);
        $this->schemaReader->method('get')->with('typesense_catalogsearch_fulltext_1')
            ->willReturn(new CollectionSchema([['name' => 'name', 'type' => 'string']]));
        $this->client->expects(self::once())->method('request')->with(
            'GET',
            self::anything(),
            null,
            self::callback(
                static fn (array $params): bool => $params['query_by'] === 'name'
                    && $params['include_fields'] === 'name'
            )
        )->willReturn(['hits' => []]);

        self::assertSame([], $this->suggestions()->getItems($this->query('jcket')));
    }

    public function testNoDeclaredSearchableFieldsMakesNoRequest(): void
    {
        $this->configure(true, 5, false);
        $this->schemaReader->method('get')->willReturn(new CollectionSchema([]));
        $this->client->expects(self::never())->method('request');

        self::assertSame([], $this->suggestions()->getItems($this->query('jcket')));
    }

    public function testResultsCountRunsOneCountSearchForCorrection(): void
    {
        $this->configure(true, 5, true);
        $calls = [];
        $this->client->expects(self::exactly(2))->method('request')->willReturnCallback(
            static function (string $method, string $path, ?array $body, array $params) use (&$calls): array {
                $calls[] = $params;

                return count($calls) === 1 ? self::hits(['Jacket'], ['jacket']) : ['found' => 42];
            }
        );

        $items = $this->suggestions()->getItems($this->query('jcket'));

        self::assertSame(42, $items[0]->getResultsCount());
        self::assertSame(
            ['q' => 'jacket', 'query_by' => 'name,sku', 'filter_by' => 'visibility:=[3,4]', 'per_page' => 0],
            $calls[1]
        );
    }

    public function testResultsCountFlagIsHonoured(): void
    {
        $this->configure(true, 5, false);
        $this->client->expects(self::once())->method('request')->willReturn(self::hits(['Jacket']));

        $items = $this->suggestions()->getItems($this->query('jcket'));

        self::assertNull($items[0]->getResultsCount());
    }

    public function testCountSearchFailureKeepsSuggestionWithZeroCount(): void
    {
        $this->configure(true, 5, true);
        $this->client->method('request')->willReturnOnConsecutiveCalls(
            self::hits(['Jacket']),
            self::throwException(new TypesenseException('boom'))
        );
        $this->logger->expects(self::once())->method('critical');

        $items = $this->suggestions()->getItems($this->query('jcket'));

        self::assertSame('jacket', $items[0]->getQueryText());
        self::assertSame(0, $items[0]->getResultsCount());
    }

    public function testTypesenseFailureDegradesToNoItems(): void
    {
        $this->configure(true, 5, false);
        $this->client->method('request')->willThrowException(new TypesenseException('boom'));
        $this->logger->expects(self::once())->method('critical');

        self::assertSame([], $this->suggestions()->getItems($this->query('shoes')));
    }

    public function testNoSearchableFieldsReturnsNoItems(): void
    {
        $this->configure(true, 5, false);
        $fields = $this->createMock(SearchableFieldsProviderInterface::class);
        $fields->method('get')->willReturn([]);
        $this->client->expects(self::never())->method('request');

        $suggestions = new Suggestions(
            $this->scopeConfig,
            $this->engineResolver,
            $this->queryResultFactory,
            $this->client,
            $fields,
            $this->indexNameResolver,
            $this->storeManager,
            $this->schemaReader,
            $this->logger
        );

        self::assertSame([], $suggestions->getItems($this->query('shoes')));
    }

    public function testZeroCountReturnsNoItems(): void
    {
        $this->configure(true, 0, false);
        $this->client->expects(self::never())->method('request');

        self::assertSame([], $this->suggestions()->getItems($this->query('shoes')));
    }

    public function testIsResultsCountEnabledReadsConfigFlag(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturnMap([
            [SuggestedQueriesInterface::SEARCH_SUGGESTION_COUNT_RESULTS_ENABLED, 'store', true],
        ]);

        self::assertTrue($this->suggestions()->isResultsCountEnabled());
    }
}
