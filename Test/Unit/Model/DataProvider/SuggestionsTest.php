<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Test\Unit\Model\DataProvider;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Client\TypesenseClient;
use DmLab\TypesenseIndexer\Api\SearchableFieldsProviderInterface;
use DmLab\TypesenseIndexer\Api\EngineCode;
use DmLab\TypesenseIndexer\Api\IndexNameResolverInterface;
use DmLab\TypesenseSearch\Model\DataProvider\Suggestions;
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

    public function testMapsHitsToSuggestionItems(): void
    {
        $this->configure(true, 5, false);
        $this->client->expects(self::once())->method('request')->willReturn([
            'hits' => [
                ['document' => ['name' => 'Blue Shoes']],
                ['document' => ['name' => 'Red Shoes']],
            ],
        ]);

        $items = $this->suggestions()->getItems($this->query('shoes'));

        self::assertCount(2, $items);
        self::assertSame('Blue Shoes', $items[0]->getQueryText());
        self::assertSame('Red Shoes', $items[1]->getQueryText());
        self::assertNull($items[0]->getResultsCount());
    }

    public function testSearchUsesQueryTextAndProviderFields(): void
    {
        $this->configure(true, 5, false);
        $this->client->expects(self::once())->method('request')->with(
            'GET',
            '/collections/typesense_catalogsearch_fulltext_1/documents/search',
            null,
            [
                'q' => 'shoes',
                'query_by' => 'name,sku',
                'per_page' => 5,
                'page' => 1,
            ]
        )->willReturn(['hits' => []]);

        self::assertSame([], $this->suggestions()->getItems($this->query('shoes')));
    }

    public function testCappedByConfiguredCount(): void
    {
        $this->configure(true, 2, false);
        $this->client->method('request')->willReturn([
            'hits' => [
                ['document' => ['name' => 'One']],
                ['document' => ['name' => 'Two']],
                ['document' => ['name' => 'Three']],
            ],
        ]);

        $items = $this->suggestions()->getItems($this->query('x'));

        self::assertCount(2, $items);
        self::assertSame('One', $items[0]->getQueryText());
        self::assertSame('Two', $items[1]->getQueryText());
    }

    public function testDuplicatesAndEmptyValuesAreSkipped(): void
    {
        $this->configure(true, 10, false);
        $this->client->method('request')->willReturn([
            'hits' => [
                ['document' => ['name' => 'Shoe']],
                ['document' => ['name' => 'Shoe']],
                ['document' => ['name' => '   ']],
                ['document' => ['sku' => 'no-name-field']],
                ['document' => ['name' => 'Boot']],
            ],
        ]);

        $items = $this->suggestions()->getItems($this->query('x'));

        self::assertCount(2, $items);
        self::assertSame('Shoe', $items[0]->getQueryText());
        self::assertSame('Boot', $items[1]->getQueryText());
    }

    public function testResultsCountEnabledAttachesPerSuggestionFrequency(): void
    {
        $this->configure(true, 5, true);
        $this->client->method('request')->willReturnOnConsecutiveCalls(
            ['hits' => [['document' => ['name' => 'Blue Shoes']]]],
            ['found' => 42]
        );

        $items = $this->suggestions()->getItems($this->query('shoes'));

        self::assertCount(1, $items);
        self::assertSame(42, $items[0]->getResultsCount());
    }

    public function testResultsCountFlagIsHonoured(): void
    {
        // Count-results disabled: only the initial hits search runs, no per-suggestion count query.
        $this->configure(true, 5, false);
        $this->client->expects(self::once())->method('request')->willReturn([
            'hits' => [['document' => ['name' => 'Blue Shoes']]],
        ]);

        $items = $this->suggestions()->getItems($this->query('shoes'));

        self::assertNull($items[0]->getResultsCount());
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
        $this->engineResolver->method('getCurrentSearchEngine')->willReturn(EngineCode::ENGINE);
        $this->scopeConfig->method('isSetFlag')->willReturn(true);
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
