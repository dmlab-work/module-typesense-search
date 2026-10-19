<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Test\Integration\SearchAdapter;

use DmLab\TypesenseCore\Model\Synonym\SynonymSetManager;
use DmLab\TypesenseIndexer\Api\FieldNameResolverInterface;
use DmLab\TypesenseIndexer\Api\IndexNameResolverInterface;
use DmLab\TypesenseIndexer\Api\SearchableFieldsProviderInterface;
use DmLab\TypesenseSearch\SearchAdapter\Adapter;
use DmLab\TypesenseSearch\SearchAdapter\Aggregation\Builder as AggregationBuilder;
use DmLab\TypesenseSearch\SearchAdapter\Field\FieldNameResolver;
use DmLab\TypesenseSearch\SearchAdapter\Query\FilterValueFormatter;
use DmLab\TypesenseSearch\SearchAdapter\Query\QueryBuilder;
use DmLab\TypesenseSearch\SearchAdapter\Query\QueryModifier;
use DmLab\TypesenseSearch\SearchAdapter\Query\SynonymSetResolver;
use DmLab\TypesenseSearch\SearchAdapter\Response\ResponseProcessor;
use DmLab\TypesenseSearch\SearchAdapter\Schema\DroppedFieldLogger;
use DmLab\TypesenseSearch\SearchAdapter\Schema\ParamsSanitizer;
use DmLab\TypesenseSearch\SearchAdapter\Schema\SchemaReader;
use DmLab\TypesenseSearch\SearchAdapter\StoreResolver;
use DmLab\TypesenseSearch\Test\Integration\Support\ArrayCache;
use DmLab\TypesenseSearch\Test\Integration\TypesenseTestCase;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\ScopeInterface;
use Magento\Framework\App\ScopeResolverInterface;
use Magento\Framework\Registry;
use Magento\Framework\Search\Dynamic\Algorithm\Repository as AlgorithmRepository;
use Magento\Framework\Search\Dynamic\DataProviderFactory;
use Magento\Framework\Search\Dynamic\EntityStorageFactory;
use Magento\Framework\Search\Request;
use Magento\Framework\Search\Request\Aggregation\TermBucket;
use Magento\Framework\Search\Request\Dimension;
use Magento\Framework\Search\Request\Filter\Term;
use Magento\Framework\Search\Request\Filter\Wildcard;
use Magento\Framework\Search\Request\FilterInterface;
use Magento\Framework\Search\Request\Query\BoolExpression;
use Magento\Framework\Search\Request\Query\Filter as FilterQuery;
use Magento\Framework\Search\Request\Query\MatchQuery;
use Magento\Framework\Search\Request\QueryInterface;
use Magento\Framework\Search\Response\QueryResponse;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Log\NullLogger;

/**
 * Real query/aggregation/schema pipeline over a real Typesense; only the indexer seams are mocked.
 */
#[AllowMockObjectsWithoutExpectations]
class AdapterIntegrationTest extends TypesenseTestCase
{
    /** @var Adapter */
    private Adapter $adapter;

    /** @var ArrayCache */
    private ArrayCache $cache;

    protected function setUp(): void
    {
        $indexNameResolver = $this->createMock(IndexNameResolverInterface::class);
        $indexNameResolver->method('getAliasName')->willReturn(self::$alias);

        $searchableFields = $this->createMock(SearchableFieldsProviderInterface::class);
        $searchableFields->method('get')->willReturn(['name' => 5, 'sku' => 6, 'color_value' => 1]);

        $schemaFieldNames = $this->createMock(FieldNameResolverInterface::class);
        $schemaFieldNames->method('resolve')->willReturnCallback(
            static fn (string $code): string => match ($code) {
                'price' => 'price_0_1',
                'position' => 'position_category_999',
                default => $code,
            }
        );

        $scope = $this->createMock(ScopeInterface::class);
        $scope->method('getId')->willReturn(1);
        $scopeResolver = $this->createMock(ScopeResolverInterface::class);
        $scopeResolver->method('getScope')->willReturn($scope);
        $storeResolver = new StoreResolver($scopeResolver);

        $store = $this->createMock(Store::class);
        $store->method('getWebsiteId')->willReturn(1);
        $store->method('getRootCategoryId')->willReturn(2);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $synonymSetManager = $this->createMock(SynonymSetManager::class);
        $synonymSetManager->method('exists')->willReturn(true);

        $logger = new NullLogger();
        $cache = $this->cache = new ArrayCache();
        $schemaReader = new SchemaReader(self::$client, $cache, new Json(), $logger);
        $droppedFieldLogger = new DroppedFieldLogger($cache, $logger);

        $queryBuilder = new QueryBuilder(
            $searchableFields,
            $storeResolver,
            new FieldNameResolver(
                $schemaFieldNames,
                $this->createMock(CustomerSession::class),
                $storeManager,
                $this->createMock(Registry::class)
            ),
            $indexNameResolver,
            new FilterValueFormatter(),
            new SynonymSetResolver($synonymSetManager, $indexNameResolver, $cache, $logger),
            $droppedFieldLogger
        );

        $this->adapter = new Adapter(
            $queryBuilder,
            new QueryModifier(),
            new AggregationBuilder(
                $this->createMock(AlgorithmRepository::class),
                $this->createMock(EntityStorageFactory::class),
                $this->createMock(DataProviderFactory::class),
                $droppedFieldLogger
            ),
            $indexNameResolver,
            $storeResolver,
            self::$client,
            $logger,
            $schemaReader,
            new ParamsSanitizer($droppedFieldLogger),
            new ResponseProcessor()
        );
    }

    public function testIntFlagFilterMatches(): void
    {
        self::assertSame(['1'], $this->ids($this->query($this->filter(new Term('f', '1', 'eco_collection')))));
    }

    public function testLegacyBoolFlagFilterMatches(): void
    {
        self::assertSame(['1'], $this->ids($this->query($this->filter(new Term('f', '1', 'legacy_flag')))));
    }

    public function testSelectFilterMatchesIntArrayField(): void
    {
        self::assertSame(['1'], $this->ids($this->query($this->filter(new Term('f', '49', 'color')))));
    }

    public function testSkuWildcardIsPrefixMatch(): void
    {
        self::assertSame(['1'], $this->ids($this->query($this->filter(new Wildcard('f', 'WSH1', 'sku')))));
    }

    public function testSortOnMissingPositionFieldIsDropped(): void
    {
        $response = $this->query($this->all(), [['field' => 'position', 'direction' => 'asc']]);

        self::assertSame(2, $response->count());
    }

    public function testFacetOnUnfacetedFieldGivesEmptyBucket(): void
    {
        $response = $this->query($this->all(), [], [new TermBucket('description_bucket', 'description', [])]);

        self::assertSame(2, $response->count());
        self::assertSame([], $response->getAggregations()->getBucket('description_bucket')->getValues());
    }

    public function testCategoryBucketValuesAreInts(): void
    {
        $response = $this->query($this->all(), [], [new TermBucket('category_bucket', 'category_ids', [])]);

        $values = [];
        foreach ($response->getAggregations()->getBucket('category_bucket')->getValues() as $value) {
            $values[$value->getValue()] = $value->getMetrics()['count'];
        }
        ksort($values);

        self::assertSame([2 => 2, 21 => 1, 22 => 1], $values);
    }

    public function testSynonymFindsDocument(): void
    {
        $query = new BoolExpression('q', 1, ['search' => new MatchQuery('search', 'jumper', 1, [['field' => '*']])]);

        self::assertSame(['1'], $this->ids($this->query($query)));
    }

    public function testSortByRegexDeclaredPriceField(): void
    {
        self::assertSame(['2', '1'], $this->ids($this->sorted('price', 'desc')));
        self::assertSame(['1', '2'], $this->ids($this->sorted('price', 'asc')));
    }

    public function testSelectSortUsesLabelField(): void
    {
        self::assertSame(['1', '2'], $this->ids($this->sorted('color', 'desc')));
        self::assertSame(['2', '1'], $this->ids($this->sorted('color', 'asc')));
    }

    public function testFilterOnUnknownFieldGivesEmptyResponse(): void
    {
        $response = $this->query($this->filter(new Term('f', '1', 'no_such_field')));

        self::assertSame(0, $response->count());
        self::assertSame(0, $response->getTotal());
    }

    public function testStaleSchemaSortIsRetriedWithoutTheClause(): void
    {
        $this->seedStaleSchema();

        $response = $this->sorted('ghost', 'asc');

        self::assertSame(2, $response->count());
        self::assertFalse($this->cache->load('dmlab_typesense_schema_' . self::$alias));
    }

    public function testStaleSchemaFacetIsRetriedWithoutTheFacet(): void
    {
        $this->seedStaleSchema();

        $response = $this->query($this->all(), [], [new TermBucket('ghost_bucket', 'ghost', [])]);

        self::assertSame(2, $response->count());
        self::assertSame([], $response->getAggregations()->getBucket('ghost_bucket')->getValues());
    }

    public function testStaleSchemaFilterGivesEmptyResponse(): void
    {
        $this->seedStaleSchema();

        $response = $this->query($this->filter(new Term('f', '1', 'ghost')));

        self::assertSame(0, $response->getTotal());
    }

    /**
     * Cache the real schema plus a sortable/facetable `ghost` field Typesense does not have.
     */
    private function seedStaleSchema(): void
    {
        $fields = self::$client->request('GET', '/collections/' . self::$alias)['fields'];
        $fields[] = ['name' => 'ghost', 'type' => 'int32', 'facet' => true, 'sort' => true];
        $this->cache->save(json_encode($fields), 'dmlab_typesense_schema_' . self::$alias);
    }

    /**
     * Run a search request against the fixture store.
     *
     * @param QueryInterface $query
     * @param array<int,array<string,string>> $sort
     * @param TermBucket[] $buckets
     */
    private function query(QueryInterface $query, array $sort = [], array $buckets = []): QueryResponse
    {
        return $this->adapter->query(
            new Request('it', 'catalogsearch_fulltext', $query, 0, 10, [new Dimension('scope', '1')], $buckets, $sort)
        );
    }

    /**
     * Match-all search sorted by one attribute.
     *
     * @param string $field
     * @param string $direction
     */
    private function sorted(string $field, string $direction): QueryResponse
    {
        return $this->query($this->all(), [['field' => $field, 'direction' => $direction]]);
    }

    /**
     * Match-all request query.
     */
    private function all(): QueryInterface
    {
        return new BoolExpression('q', 1);
    }

    /**
     * Request query with a single must filter.
     *
     * @param FilterInterface $filter
     */
    private function filter(FilterInterface $filter): QueryInterface
    {
        return new BoolExpression('q', 1, ['f' => new FilterQuery('f', 1, FilterQuery::REFERENCE_FILTER, $filter)]);
    }

    /**
     * Document ids in hit order.
     *
     * @param QueryResponse $response
     * @return string[]
     */
    private function ids(QueryResponse $response): array
    {
        $ids = [];
        foreach ($response as $document) {
            $ids[] = (string)$document->getId();
        }

        return $ids;
    }
}
