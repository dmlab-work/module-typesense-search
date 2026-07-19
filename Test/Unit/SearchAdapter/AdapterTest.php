<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseSearch\Test\Unit\SearchAdapter;

use MageDevGroup\TypesenseCore\Exception\TransportException;
use MageDevGroup\TypesenseCore\Exception\TypesenseException;
use MageDevGroup\TypesenseCore\Model\Client\TypesenseClient;
use MageDevGroup\TypesenseIndexer\Api\IndexNameResolverInterface;
use MageDevGroup\TypesenseSearch\Exception\UnsupportedSearchRequestException;
use MageDevGroup\TypesenseSearch\SearchAdapter\Adapter;
use MageDevGroup\TypesenseSearch\SearchAdapter\Aggregation\Builder as AggregationBuilder;
use MageDevGroup\TypesenseSearch\SearchAdapter\Query\QueryBuilder;
use MageDevGroup\TypesenseSearch\SearchAdapter\Query\QueryModifier;
use MageDevGroup\TypesenseSearch\SearchAdapter\StoreResolver;
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
            $this->logger
        );
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
        // A construct Typesense cannot express (e.g. Advanced Search's SKU wildcard filter)
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

    public function testFieldLevel404IsMappedToClientException(): void
    {
        // A `sort_by`/`facet_by` on a field the schema does not mark sortable/facetable also
        // returns HTTP 404 — it must surface loudly, not silently empty the whole listing.
        $this->queryBuilder->method('build')->willReturn(['q' => '*']);
        $this->queryModifier->method('modify')->willReturnArgument(0);
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');
        $cause = new TypesenseException(
            'Typesense GET ... failed with HTTP 404: Could not find a field named `name` in the schema for sorting.',
            404,
            ['message' => 'Could not find a field named `name` in the schema for sorting.']
        );
        $this->client->method('request')->willThrowException($cause);
        $this->logger->expects($this->never())->method('warning');
        $this->logger->expects($this->once())->method('critical');

        $this->expectException(ClientException::class);

        try {
            $this->adapter()->query($this->request());
        } catch (ClientException $e) {
            $this->assertSame($cause, $e->getPrevious());
            throw $e;
        }
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
