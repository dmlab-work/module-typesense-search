<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Test\Unit\SearchAdapter;

use DmLab\TypesenseCore\Exception\TransportException;
use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Client\TypesenseClient;
use DmLab\TypesenseIndexer\Api\IndexNameResolverInterface;
use DmLab\TypesenseSearch\Exception\UnsupportedSearchRequestException;
use DmLab\TypesenseSearch\SearchAdapter\Adapter;
use DmLab\TypesenseSearch\SearchAdapter\Aggregation\Builder as AggregationBuilder;
use DmLab\TypesenseSearch\SearchAdapter\Query\QueryBuilder;
use DmLab\TypesenseSearch\SearchAdapter\Query\QueryModifier;
use DmLab\TypesenseSearch\SearchAdapter\Response\ResponseProcessor;
use DmLab\TypesenseSearch\SearchAdapter\Response\ResponseProcessorInterface;
use DmLab\TypesenseSearch\SearchAdapter\Schema\CollectionSchema;
use DmLab\TypesenseSearch\SearchAdapter\Schema\DroppedFieldLogger;
use DmLab\TypesenseSearch\SearchAdapter\Schema\ParamsSanitizer;
use DmLab\TypesenseSearch\SearchAdapter\Schema\SchemaReader;
use DmLab\TypesenseSearch\SearchAdapter\StoreResolver;
use Magento\AdvancedSearch\Model\Client\ClientException;
use Magento\CatalogSearch\Model\Indexer\Fulltext;
use Magento\Framework\App\ScopeInterface;
use Magento\Framework\App\ScopeResolverInterface;
use Magento\Framework\Search\Request\Dimension;
use Magento\Framework\Search\RequestInterface;
use Magento\Framework\Search\Response\Aggregation;
use Magento\Framework\Search\Response\QueryResponse;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class AdapterTest extends TestCase
{
    /** @var QueryBuilder */
    private QueryBuilder $queryBuilder;
    /** @var QueryModifier */
    private QueryModifier $queryModifier;
    /** @var AggregationBuilder */
    private AggregationBuilder $aggregationBuilder;
    /** @var IndexNameResolverInterface */
    private IndexNameResolverInterface $indexNameResolver;
    /** @var ScopeResolverInterface */
    private ScopeResolverInterface $scopeResolver;
    /** @var TypesenseClient */
    private TypesenseClient $client;
    /** @var LoggerInterface */
    private LoggerInterface $logger;
    /** @var SchemaReader */
    private SchemaReader $schemaReader;
    /** @var ResponseProcessorInterface[] */
    private array $processors = [];

    protected function setUp(): void
    {
        $this->queryBuilder = $this->createMock(QueryBuilder::class);
        $this->queryModifier = $this->createMock(QueryModifier::class);
        $this->aggregationBuilder = $this->createMock(AggregationBuilder::class);
        $this->aggregationBuilder->method('addAggregations')->willReturnArgument(0);
        $this->aggregationBuilder->method('build')->willReturn(new Aggregation([]));
        $this->indexNameResolver = $this->createMock(IndexNameResolverInterface::class);
        $this->scopeResolver = $this->createMock(ScopeResolverInterface::class);
        $this->client = $this->createMock(TypesenseClient::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->schemaReader = $this->createMock(SchemaReader::class);
    }

    private function adapter(): Adapter
    {
        return new Adapter(
            $this->queryBuilder,
            $this->queryModifier,
            $this->aggregationBuilder,
            $this->indexNameResolver,
            new StoreResolver($this->scopeResolver),
            $this->client,
            $this->logger,
            $this->schemaReader,
            new ParamsSanitizer($this->createMock(DroppedFieldLogger::class)),
            new ResponseProcessor($this->processors)
        );
    }

    private function fieldNotFound(string $message): TypesenseException
    {
        return new TypesenseException('Typesense GET ... failed with HTTP 404: ' . $message, 404, [
            'message' => $message,
        ]);
    }

    private function request(int $storeId = 1): RequestInterface
    {
        $scope = $this->createMock(ScopeInterface::class);
        $scope->method('getId')->willReturn($storeId);
        $this->scopeResolver->method('getScope')->willReturn($scope);

        $request = $this->createMock(RequestInterface::class);
        $request->method('getDimensions')->willReturn([new Dimension('scope', (string)$storeId)]);

        return $request;
    }

    public function testMapsHitsToDocumentsWithIdsAndScoresInOrder(): void
    {
        $this->queryBuilder->method('build')->willReturn(['q' => '*']);
        $this->queryModifier->method('modify')->willReturnArgument(0);
        $this->indexNameResolver->method('getAliasName')->willReturn('typesense_catalogsearch_fulltext_1');
        $this->client->method('request')->willReturn([
            'found' => 2,
            'hits' => [
                ['document' => ['id' => '10'], 'text_match' => 130342],
                ['document' => ['id' => '25'], 'text_match' => 98765],
            ],
        ]);

        $response = $this->adapter()->query($this->request());

        $this->assertInstanceOf(QueryResponse::class, $response);
        $this->assertSame(2, $response->getTotal());
        $documents = iterator_to_array($response->getIterator());
        $this->assertCount(2, $documents);
        $this->assertSame('10', $documents[0]->getId());
        $this->assertSame('25', $documents[1]->getId());
        $this->assertSame(130342, $documents[0]->getCustomAttribute('score')->getValue());
        $this->assertSame(98765, $documents[1]->getCustomAttribute('score')->getValue());
    }

    public function testResolvesAliasFromIndexerForTheRequestStore(): void
    {
        $this->queryBuilder->method('build')->willReturn(['q' => '*']);
        $this->queryModifier->method('modify')->willReturnArgument(0);
        $this->indexNameResolver->expects($this->once())
            ->method('getAliasName')
            ->with(Fulltext::INDEXER_ID, 3)
            ->willReturn('typesense_catalogsearch_fulltext_3');
        $this->client->expects($this->once())
            ->method('request')
            ->with('GET', '/collections/typesense_catalogsearch_fulltext_3/documents/search')
            ->willReturn(['found' => 0, 'hits' => []]);

        $this->adapter()->query($this->request(3));
    }

    public function testAppliesModifierPipelineToBuilderOutputBeforeSearch(): void
    {
        $this->queryBuilder->method('build')->willReturn(['q' => 'shoes', 'query_by' => 'name']);
        $this->queryModifier->expects($this->once())
            ->method('modify')
            ->with(['q' => 'shoes', 'query_by' => 'name'])
            ->willReturn(['q' => 'shoes', 'query_by' => 'name', 'filter_by' => 'in_stock:=true']);
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');
        $this->client->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                '/collections/alias/documents/search',
                null,
                ['q' => 'shoes', 'query_by' => 'name', 'filter_by' => 'in_stock:=true']
            )
            ->willReturn(['found' => 0, 'hits' => []]);

        $this->adapter()->query($this->request());
    }

    public function testPipelineOrderAndModifierSeesFacetParams(): void
    {
        $order = [];
        $request = $this->request();
        $this->queryBuilder->method('build')->willReturnCallback(function () use (&$order) {
            $order[] = 'build';

            return ['q' => '*'];
        });
        $this->aggregationBuilder = $this->createMock(AggregationBuilder::class);
        $this->aggregationBuilder->method('addAggregations')->willReturnCallback(
            function (array $params) use (&$order) {
                $order[] = 'aggregations';

                return $params + ['facet_by' => 'color', 'max_facet_values' => 10000];
            }
        );
        $this->aggregationBuilder->method('build')->willReturnCallback(function () use (&$order) {
            $order[] = 'map';

            return new Aggregation([]);
        });
        $this->queryModifier->expects($this->once())
            ->method('modify')
            ->with(['q' => '*', 'facet_by' => 'color', 'max_facet_values' => 10000], $request)
            ->willReturnCallback(function (array $params) use (&$order) {
                $order[] = 'modify';

                return $params;
            });
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');
        $this->client->method('request')->willReturnCallback(function () use (&$order) {
            $order[] = 'search';

            return ['found' => 0, 'hits' => []];
        });
        $this->processors = [$this->processor(function (array $response) use (&$order) {
            $order[] = 'process';

            return $response;
        })];

        $this->adapter()->query($request);

        $this->assertSame(['build', 'aggregations', 'modify', 'search', 'process', 'map'], $order);
    }

    public function testModifierAddedSortOnMissingFieldIsSanitized(): void
    {
        $schema = new CollectionSchema([['name' => 'name', 'type' => 'string', 'sort' => true]]);
        $this->queryBuilder->method('build')->willReturn(['q' => '*', 'sort_by' => 'name:asc']);
        $this->queryModifier->method('modify')->willReturnCallback(
            static fn (array $params): array => ['sort_by' => 'boost_score:desc,' . $params['sort_by']] + $params
        );
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');
        $this->schemaReader->method('get')->willReturn($schema);
        $this->client->expects($this->once())
            ->method('request')
            ->with('GET', '/collections/alias/documents/search', null, ['sort_by' => 'name:asc', 'q' => '*'])
            ->willReturn(['found' => 0, 'hits' => []]);

        $this->adapter()->query($this->request());
    }

    public function testMatchQueryReplaysModifierParamsAndDropsPageShapingKeys(): void
    {
        $params = [
            'q' => 'jacket',
            'query_by' => 'name',
            'filter_by' => 'visibility:=[3,4]',
            'vector_query' => 'embedding:([], k:10)',
            'synonym_sets' => 'alias_synonyms',
            'hidden_hits' => '7',
            'pinned_hits' => '5:1',
            'page' => 1,
            'per_page' => 12,
            'sort_by' => '_text_match:desc',
            'facet_by' => 'color',
            'max_facet_values' => 10000,
            'include_fields' => 'id',
        ];
        $this->queryBuilder->method('build')->willReturn($params);
        $this->queryModifier->method('modify')->willReturnArgument(0);
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');
        $this->client->method('request')->willReturn(['found' => 0, 'hits' => []]);
        $this->aggregationBuilder = $this->createMock(AggregationBuilder::class);
        $this->aggregationBuilder->method('addAggregations')->willReturnArgument(0);
        $this->aggregationBuilder->expects($this->once())
            ->method('build')
            ->with(
                $this->anything(),
                $this->anything(),
                [
                    'q' => 'jacket',
                    'query_by' => 'name',
                    'filter_by' => 'visibility:=[3,4]',
                    'vector_query' => 'embedding:([], k:10)',
                    'synonym_sets' => 'alias_synonyms',
                    'hidden_hits' => '7',
                    'pinned_hits' => '5:1',
                ]
            )
            ->willReturn(new Aggregation([]));

        $this->adapter()->query($this->request());
    }

    public function testProcessorOutputIsWhatGetsMapped(): void
    {
        $this->queryBuilder->method('build')->willReturn(['q' => '*']);
        $this->queryModifier->method('modify')->willReturnArgument(0);
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');
        $raw = ['found' => 1, 'hits' => [['document' => ['id' => '1'], 'text_match' => 1]]];
        $processed = ['found' => 2, 'hits' => [
            ['document' => ['id' => '9'], 'text_match' => 5],
            ['document' => ['id' => '1'], 'text_match' => 1],
        ]];
        $this->client->method('request')->willReturn($raw);
        $this->aggregationBuilder = $this->createMock(AggregationBuilder::class);
        $this->aggregationBuilder->method('addAggregations')->willReturnArgument(0);
        $this->aggregationBuilder->expects($this->once())
            ->method('build')
            ->with($this->anything(), $processed)
            ->willReturn(new Aggregation([]));
        $this->processors = [$this->processor(static fn (array $response, array $params): array =>
            $response === $raw && $params === ['q' => '*'] ? $processed : [])];

        $response = $this->adapter()->query($this->request());

        $this->assertSame(2, $response->getTotal());
        $this->assertSame(['9', '1'], array_map(
            static fn ($document) => $document->getId(),
            iterator_to_array($response->getIterator())
        ));
    }

    /**
     * @param callable(array, array): array $callback
     */
    private function processor(callable $callback): ResponseProcessorInterface
    {
        return new class ($callback) implements ResponseProcessorInterface {
            /** @var callable */
            private $callback;

            public function __construct(callable $callback)
            {
                $this->callback = $callback;
            }

            public function process(array $response, array $params, RequestInterface $request): array
            {
                return ($this->callback)($response, $params);
            }
        };
    }

    public function testHandsTheLoadedSchemaToTheQueryAndAggregationBuilders(): void
    {
        $schema = new CollectionSchema([['name' => 'category_ids', 'type' => 'int32[]', 'facet' => true]]);
        $this->queryBuilder->expects($this->once())
            ->method('build')
            ->with($this->anything(), $this->identicalTo($schema))
            ->willReturn(['q' => '*']);
        $this->queryModifier->method('modify')->willReturnArgument(0);
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');
        $this->schemaReader->expects($this->once())->method('get')->with('alias')->willReturn($schema);
        $raw = ['found' => 0, 'hits' => []];
        $this->client->method('request')->willReturn($raw);
        $this->aggregationBuilder = $this->createMock(AggregationBuilder::class);
        $this->aggregationBuilder->expects($this->once())
            ->method('addAggregations')
            ->with(['q' => '*'], $this->anything(), $this->identicalTo($schema))
            ->willReturnArgument(0);
        $this->aggregationBuilder->expects($this->once())
            ->method('build')
            ->with($this->anything(), $raw, ['q' => '*'], 1, $this->identicalTo($schema))
            ->willReturn(new Aggregation([]));

        $this->adapter()->query($this->request());
    }

    public function testEmptyResultYieldsEmptyResponse(): void
    {
        $this->queryBuilder->method('build')->willReturn(['q' => '*']);
        $this->queryModifier->method('modify')->willReturnArgument(0);
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');
        $this->client->method('request')->willReturn(['found' => 0, 'hits' => []]);

        $response = $this->adapter()->query($this->request());

        $this->assertSame(0, $response->getTotal());
        $this->assertCount(0, $response);
    }

    public function testTextSearchWithNoSearchableFieldsDegradesWithoutHittingTypesense(): void
    {
        // Empty query_by would make Typesense reject the search (HTTP 400); the adapter
        // must degrade to an empty response and never issue the doomed request.
        $this->queryBuilder->method('build')->willReturn(['q' => 'shoes', 'query_by' => '']);
        $this->queryModifier->method('modify')->willReturnArgument(0);
        $this->client->expects($this->never())->method('request');

        $response = $this->adapter()->query($this->request());

        $this->assertSame(0, $response->getTotal());
        $this->assertCount(0, $response);
    }

    public function testUnsupportedRequestDegradesToEmptyResponseWithoutHittingTypesense(): void
    {
        // A construct Typesense cannot express (e.g. a negated wildcard filter)
        // must degrade to an empty response, not propagate and fatal the page with a 500.
        $this->queryBuilder->method('build')
            ->willThrowException(new UnsupportedSearchRequestException('wildcard unsupported'));
        $this->client->expects($this->never())->method('request');
        $this->logger->expects($this->once())->method('warning');

        $response = $this->adapter()->query($this->request());

        $this->assertSame(0, $response->getTotal());
        $this->assertCount(0, $response);
    }

    public function testSkipsHitsWithoutAnId(): void
    {
        $this->queryBuilder->method('build')->willReturn(['q' => '*']);
        $this->queryModifier->method('modify')->willReturnArgument(0);
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');
        $this->client->method('request')->willReturn([
            'found' => 2,
            'hits' => [
                ['document' => ['name' => 'no id here'], 'text_match' => 1],
                ['document' => ['id' => '7'], 'text_match' => 2],
            ],
        ]);

        $documents = iterator_to_array($this->adapter()->query($this->request())->getIterator());

        $this->assertCount(1, $documents);
        $this->assertSame('7', $documents[0]->getId());
    }

    public function testWindowLargerThanTheCapIsPagedAndConcatenated(): void
    {
        $this->queryBuilder->method('build')->willReturn(['q' => '*', 'per_page' => 300, 'page' => 1]);
        $this->queryModifier->method('modify')->willReturnArgument(0);
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');

        $calls = [];
        $this->client->method('request')->willReturnCallback(
            function ($method, $path, $body, array $params) use (&$calls) {
                $calls[] = $params;
                $hits = [];
                for ($i = 0; $i < $params['limit']; $i++) {
                    $hits[] = ['document' => ['id' => (string)($params['offset'] + $i)], 'text_match' => 1];
                }

                return ['found' => 300, 'hits' => $hits];
            }
        );

        $response = $this->adapter()->query($this->request());

        $this->assertSame(300, $response->getTotal());
        $this->assertCount(300, iterator_to_array($response->getIterator()));
        $this->assertCount(2, $calls);
        $this->assertSame(0, $calls[0]['offset']);
        $this->assertSame(250, $calls[0]['limit']);
        $this->assertSame(250, $calls[1]['offset']);
        $this->assertSame(50, $calls[1]['limit']);
        $this->assertArrayNotHasKey('per_page', $calls[0]);
        $this->assertArrayNotHasKey('page', $calls[0]);
    }

    public function testLargeFanOutWindowIsLoggedForOperatorVisibility(): void
    {
        // A window over ~10 round-trips (per_page cap 250) is worth surfacing so operators can see
        // the read amplification; the result itself is unchanged.
        $this->queryBuilder->method('build')->willReturn(['q' => '*', 'per_page' => 3000, 'page' => 1]);
        $this->queryModifier->method('modify')->willReturnArgument(0);
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');
        $this->client->method('request')->willReturnCallback(
            static function ($method, $path, $body, array $params) {
                $hits = [];
                for ($i = 0; $i < $params['limit']; $i++) {
                    $hits[] = ['document' => ['id' => (string)($params['offset'] + $i)], 'text_match' => 1];
                }

                return ['found' => 3000, 'hits' => $hits];
            }
        );
        $this->logger->expects($this->once())->method('warning');

        $response = $this->adapter()->query($this->request());

        $this->assertSame(3000, $response->getTotal());
    }

    public function testWindowAtOrBelowFanOutThresholdIsNotLogged(): void
    {
        $this->queryBuilder->method('build')->willReturn(['q' => '*', 'per_page' => 300, 'page' => 1]);
        $this->queryModifier->method('modify')->willReturnArgument(0);
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');
        $this->client->method('request')->willReturnCallback(
            static function ($method, $path, $body, array $params) {
                $hits = [];
                for ($i = 0; $i < $params['limit']; $i++) {
                    $hits[] = ['document' => ['id' => (string)($params['offset'] + $i)], 'text_match' => 1];
                }

                return ['found' => 300, 'hits' => $hits];
            }
        );
        $this->logger->expects($this->never())->method('warning');

        $this->adapter()->query($this->request());
    }

    public function testMissingCollectionDegradesToEmptyResponse(): void
    {
        $this->queryBuilder->method('build')->willReturn(['q' => '*']);
        $this->queryModifier->method('modify')->willReturnArgument(0);
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');
        $this->client->method('request')->willThrowException(
            new TypesenseException('Typesense GET ... failed with HTTP 404: Collection not found', 404, [
                'message' => 'Collection not found',
            ])
        );
        $this->logger->expects($this->once())->method('warning');
        $this->logger->expects($this->never())->method('critical');

        $response = $this->adapter()->query($this->request());

        $this->assertSame(0, $response->getTotal());
        $this->assertCount(0, $response);
    }

    public function testSortFieldNotFoundRetriesOnceWithoutThatClause(): void
    {
        $this->queryBuilder->method('build')->willReturn(['q' => '*', 'sort_by' => 'sort_color:asc,name:desc']);
        $this->queryModifier->method('modify')->willReturnArgument(0);
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');
        $calls = [];
        $this->client->expects($this->exactly(2))->method('request')->willReturnCallback(
            function ($method, $path, $body, array $params) use (&$calls) {
                $calls[] = $params;
                if (count($calls) === 1) {
                    throw $this->fieldNotFound('Could not find a field named `sort_color` in the schema for sorting.');
                }

                return ['found' => 1, 'hits' => [['document' => ['id' => '5'], 'text_match' => 1]]];
            }
        );
        $this->schemaReader->expects($this->once())->method('invalidate')->with('alias');
        $this->logger->expects($this->once())->method('critical');

        $response = $this->adapter()->query($this->request());

        $this->assertSame(1, $response->getTotal());
        $this->assertSame(['q' => '*', 'sort_by' => 'name:desc'], $calls[1]);
    }

    public function testRegexCoveredSortFieldNotFoundRetriesWithoutInvalidatingTheSchema(): void
    {
        $this->queryBuilder->method('build')->willReturn(
            ['q' => '*', 'sort_by' => 'position_category_99:asc,name:desc']
        );
        $this->queryModifier->method('modify')->willReturnArgument(0);
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');
        $this->schemaReader->method('get')->willReturn(new CollectionSchema([
            ['name' => 'name', 'type' => 'string', 'sort' => true],
            ['name' => 'position_category_.*', 'type' => 'int32', 'sort' => true],
        ]));
        $calls = [];
        $this->client->expects($this->exactly(2))->method('request')->willReturnCallback(
            function ($method, $path, $body, array $params) use (&$calls) {
                $calls[] = $params;
                if (count($calls) === 1) {
                    throw $this->fieldNotFound(
                        'Could not find a field named `position_category_99` in the schema for sorting.'
                    );
                }

                return ['found' => 0, 'hits' => []];
            }
        );
        $this->schemaReader->expects($this->never())->method('invalidate');

        $this->adapter()->query($this->request());

        $this->assertSame(['q' => '*', 'sort_by' => 'name:desc'], $calls[1]);
    }

    public function testExactlyDeclaredSortFieldNotFoundInvalidatesTheSchema(): void
    {
        $this->queryBuilder->method('build')->willReturn(['q' => '*', 'sort_by' => 'sort_color:asc']);
        $this->queryModifier->method('modify')->willReturnArgument(0);
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');
        $this->schemaReader->method('get')->willReturn(new CollectionSchema([
            ['name' => 'sort_color', 'type' => 'string', 'sort' => true],
            ['name' => 'sort_.*', 'type' => 'string', 'sort' => true],
        ]));
        $calls = 0;
        $this->client->method('request')->willReturnCallback(
            function () use (&$calls) {
                if (++$calls === 1) {
                    throw $this->fieldNotFound('Could not find a field named `sort_color` in the schema for sorting.');
                }

                return ['found' => 0, 'hits' => []];
            }
        );
        $this->schemaReader->expects($this->once())->method('invalidate')->with('alias');

        $this->adapter()->query($this->request());
    }

    public function testFacetFieldNotFoundRetriesWithoutThatFacet(): void
    {
        $this->queryBuilder->method('build')->willReturn(['q' => '*']);
        $this->queryModifier->method('modify')->willReturnArgument(0);
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');
        $this->aggregationBuilder = $this->createMock(AggregationBuilder::class);
        $this->aggregationBuilder->method('addAggregations')->willReturn(
            ['q' => '*', 'facet_by' => 'activity', 'max_facet_values' => 10000]
        );
        $this->aggregationBuilder->method('build')->willReturn(new Aggregation([]));
        $calls = [];
        $this->client->expects($this->exactly(2))->method('request')->willReturnCallback(
            function ($method, $path, $body, array $params) use (&$calls) {
                $calls[] = $params;
                if (count($calls) === 1) {
                    throw $this->fieldNotFound('Could not find a facet field named `activity` in the schema.');
                }

                return ['found' => 0, 'hits' => []];
            }
        );
        $this->schemaReader->expects($this->once())->method('invalidate')->with('alias');

        $this->adapter()->query($this->request());

        $this->assertSame(['q' => '*', 'max_facet_values' => 10000], $calls[1]);
    }

    public function testFilterFieldNotFoundDegradesToEmptyResponse(): void
    {
        $this->queryBuilder->method('build')->willReturn(['q' => '*', 'filter_by' => 'activity:=5']);
        $this->queryModifier->method('modify')->willReturnArgument(0);
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');
        $this->client->expects($this->once())->method('request')->willThrowException(
            $this->fieldNotFound('Could not find a filter field named `activity` in the schema.')
        );
        $this->schemaReader->expects($this->once())->method('invalidate')->with('alias');
        $this->logger->expects($this->once())->method('critical');

        $response = $this->adapter()->query($this->request());

        $this->assertSame(0, $response->getTotal());
        $this->assertCount(0, $response);
    }

    public function testFieldInSortAndFacetDropsOnlyTheClauseTheErrorNames(): void
    {
        $this->queryBuilder->method('build')->willReturn(['q' => '*', 'sort_by' => 'size:asc']);
        $this->queryModifier->method('modify')->willReturnArgument(0);
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');
        $this->aggregationBuilder = $this->createMock(AggregationBuilder::class);
        $this->aggregationBuilder->method('addAggregations')->willReturn(
            ['q' => '*', 'sort_by' => 'size:asc', 'facet_by' => 'size']
        );
        $this->aggregationBuilder->method('build')->willReturn(new Aggregation([]));
        $calls = [];
        $this->client->expects($this->exactly(2))->method('request')->willReturnCallback(
            function ($method, $path, $body, array $params) use (&$calls) {
                $calls[] = $params;
                if (count($calls) === 1) {
                    throw $this->fieldNotFound('Could not find a facet field named `size` in the schema.');
                }

                return ['found' => 0, 'hits' => []];
            }
        );

        $this->adapter()->query($this->request());

        $this->assertSame(['q' => '*', 'sort_by' => 'size:asc'], $calls[1]);
    }

    public function testFilterFieldNotFoundOnRetryDegradesToEmptyResponse(): void
    {
        $this->queryBuilder->method('build')->willReturn(
            ['q' => '*', 'filter_by' => 'price_1_1:>10', 'sort_by' => 'price_1_1:asc']
        );
        $this->queryModifier->method('modify')->willReturnArgument(0);
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');
        $this->client->expects($this->exactly(2))->method('request')->willReturnOnConsecutiveCalls(
            $this->throwException(
                $this->fieldNotFound('Could not find a field named `price_1_1` in the schema for sorting.')
            ),
            $this->throwException(
                $this->fieldNotFound('Could not find a filter field named `price_1_1` in the schema.')
            )
        );
        $this->schemaReader->expects($this->exactly(2))->method('invalidate')->with('alias');

        $response = $this->adapter()->query($this->request());

        $this->assertSame(0, $response->getTotal());
    }

    public function testSecondFieldNotFoundIsNotRetried(): void
    {
        $this->queryBuilder->method('build')->willReturn(['q' => '*', 'sort_by' => 'a:asc,b:asc']);
        $this->queryModifier->method('modify')->willReturnArgument(0);
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');
        $last = $this->fieldNotFound('Could not find a field named `b` in the schema for sorting.');
        $this->client->expects($this->exactly(2))->method('request')->willReturnOnConsecutiveCalls(
            $this->throwException($this->fieldNotFound('Could not find a field named `a` in the schema for sorting.')),
            $this->throwException($last)
        );
        $this->schemaReader->expects($this->exactly(2))->method('invalidate')->with('alias');

        $this->expectException(ClientException::class);

        try {
            $this->adapter()->query($this->request());
        } catch (ClientException $e) {
            $this->assertSame($last, $e->getPrevious());
            throw $e;
        }
    }

    public function testQueryByFieldNotFoundIsMappedToClientException(): void
    {
        $this->queryBuilder->method('build')->willReturn(['q' => 'shoes', 'query_by' => 'name,gone']);
        $this->queryModifier->method('modify')->willReturnArgument(0);
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');
        $this->client->expects($this->once())->method('request')->willThrowException(
            $this->fieldNotFound('Could not find a field named `gone` in the schema.')
        );

        $this->expectException(ClientException::class);

        $this->adapter()->query($this->request());
    }

    public function testShortChunkEndsTheWindow(): void
    {
        $this->queryBuilder->method('build')->willReturn(['q' => '*', 'per_page' => 10000, 'page' => 1]);
        $this->queryModifier->method('modify')->willReturnArgument(0);
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');
        $calls = 0;
        $this->client->method('request')->willReturnCallback(
            function ($method, $path, $body, array $params) use (&$calls) {
                $calls++;
                $hits = [];
                $end = min(300, $params['offset'] + $params['limit']);
                for ($i = $params['offset']; $i < $end; $i++) {
                    $hits[] = ['document' => ['id' => (string)$i], 'text_match' => 1];
                }

                return ['found' => 300, 'hits' => $hits];
            }
        );

        $response = $this->adapter()->query($this->request());

        $this->assertSame(2, $calls);
        $this->assertCount(300, iterator_to_array($response->getIterator()));
    }

    public function testFilterFieldNotFoundOnFirstChunkEmptiesTheWindow(): void
    {
        $this->queryBuilder->method('build')->willReturn(
            ['q' => '*', 'filter_by' => 'gone:=1', 'per_page' => 1000, 'page' => 1]
        );
        $this->queryModifier->method('modify')->willReturnArgument(0);
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');
        $this->client->expects($this->once())->method('request')->willThrowException(
            $this->fieldNotFound('Could not find a filter field named `gone` in the schema.')
        );

        $response = $this->adapter()->query($this->request());

        $this->assertSame(0, $response->getTotal());
        $this->assertCount(0, iterator_to_array($response->getIterator()));
    }

    public function testServerErrorIsMappedToClientException(): void
    {
        $this->queryBuilder->method('build')->willReturn(['q' => '*']);
        $this->queryModifier->method('modify')->willReturnArgument(0);
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');
        $cause = new TypesenseException('Internal error', 500);
        $this->client->method('request')->willThrowException($cause);
        $this->logger->expects($this->once())->method('critical');

        $this->expectException(ClientException::class);

        try {
            $this->adapter()->query($this->request());
        } catch (ClientException $e) {
            $this->assertSame($cause, $e->getPrevious());
            throw $e;
        }
    }

    public function testUnreachableNodeIsMappedToClientException(): void
    {
        $this->queryBuilder->method('build')->willReturn(['q' => '*']);
        $this->queryModifier->method('modify')->willReturnArgument(0);
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');
        $this->client->method('request')->willThrowException(
            new TransportException('No node answered')
        );
        $this->logger->expects($this->once())->method('critical');

        $this->expectException(ClientException::class);

        $this->adapter()->query($this->request());
    }
}
