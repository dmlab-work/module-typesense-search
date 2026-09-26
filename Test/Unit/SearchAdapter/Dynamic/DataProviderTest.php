<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Test\Unit\SearchAdapter\Dynamic;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Client\TypesenseClient;
use DmLab\TypesenseIndexer\Api\IndexNameResolverInterface;
use DmLab\TypesenseSearch\SearchAdapter\Aggregation\Interval;
use DmLab\TypesenseSearch\SearchAdapter\Dynamic\DataProvider;
use DmLab\TypesenseSearch\SearchAdapter\Field\FieldNameResolver;
use Magento\Catalog\Model\Layer\Filter\Price\Range;
use Magento\Framework\Search\Dynamic\EntityStorage;
use Magento\Framework\Search\Dynamic\IntervalFactory;
use Magento\Framework\Search\Request\BucketInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class DataProviderTest extends TestCase
{
    /** @var TypesenseClient&MockObject */
    private $client;
    /** @var IndexNameResolverInterface&MockObject */
    private $indexNameResolver;
    /** @var Range&MockObject */
    private $range;
    /** @var IntervalFactory&MockObject */
    private $intervalFactory;
    /** @var FieldNameResolver&MockObject */
    private $fieldNameResolver;
    /** @var LoggerInterface&MockObject */
    private $logger;

    protected function setUp(): void
    {
        $this->client = $this->createMock(TypesenseClient::class);
        $this->indexNameResolver = $this->createMock(IndexNameResolverInterface::class);
        $this->indexNameResolver->method('getAliasName')->willReturn('typesense_catalogsearch_fulltext_1');
        $this->range = $this->createMock(Range::class);
        $this->intervalFactory = $this->createMock(IntervalFactory::class);
        // The price attribute resolves to its scoped document field before every Typesense query.
        $this->fieldNameResolver = $this->createMock(FieldNameResolver::class);
        $this->fieldNameResolver->method('resolve')->willReturnCallback(
            static fn (string $field): string => $field === 'price' ? 'price_0_1' : $field
        );
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function provider(): DataProvider
    {
        return new DataProvider(
            $this->client,
            $this->indexNameResolver,
            $this->range,
            $this->intervalFactory,
            $this->fieldNameResolver,
            $this->logger
        );
    }

    public function testGetRangeDelegatesToPriceRange(): void
    {
        $this->range->method('getPriceRange')->willReturn(100);

        $this->assertSame(100, $this->provider()->getRange());
    }

    public function testGetAggregationsMapsFacetStatsOverTheMatchSet(): void
    {
        // The price stats are computed over the same query the search ran (q + filter_by), not the
        // returned page's ids — so the slider range spans the whole result set.
        $this->client->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                '/collections/typesense_catalogsearch_fulltext_1/documents/search',
                null,
                [
                    'q' => 'shoes',
                    'query_by' => 'name,sku',
                    'filter_by' => 'category_ids:=5',
                    'facet_by' => 'price_0_1',
                    'max_facet_values' => 10000,
                    'per_page' => 0,
                ]
            )
            ->willReturn([
                'found' => 60,
                'facet_counts' => [
                    ['field_name' => 'price_0_1', 'stats' => ['min' => 50.0, 'max' => 250.0, 'total_values' => 3]],
                ],
            ]);

        $provider = $this->provider();
        $provider->setMatchContext(1, ['q' => 'shoes', 'query_by' => 'name,sku', 'filter_by' => 'category_ids:=5']);

        $this->assertSame(
            ['count' => 60, 'max' => 250.0, 'min' => 50.0, 'std' => 0],
            $provider->getAggregations(new EntityStorage([]))
        );
    }

    public function testGetAggregationsDegradesToZeroesOnFailure(): void
    {
        $this->client->method('request')->willThrowException(new TypesenseException('boom', 500));
        $this->logger->expects($this->once())->method('critical');

        $provider = $this->provider();
        $provider->setMatchContext(1, ['q' => '*']);

        $this->assertSame(
            ['count' => 0, 'max' => 0.0, 'min' => 0.0, 'std' => 0],
            $provider->getAggregations(new EntityStorage([]))
        );
    }

    public function testGetAggregationsWithoutMatchContextNeverQueriesTheWholeCatalog(): void
    {
        // Without a bound match set the provider must not issue a query at all (which would compute
        // stats over the entire catalog); it degrades to zeroed stats instead.
        $this->client->expects($this->never())->method('request');

        $this->assertSame(
            ['count' => 0, 'max' => 0.0, 'min' => 0.0, 'std' => 0],
            $this->provider()->getAggregations(new EntityStorage([]))
        );
    }

    public function testGetAggregationBucketsCountsByRange(): void
    {
        $this->client->method('request')->willReturn([
            'facet_counts' => [
                [
                    'field_name' => 'price',
                    'counts' => [
                        ['value' => '50', 'count' => 2],
                        ['value' => '150', 'count' => 3],
                        ['value' => '199', 'count' => 1],
                    ],
                ],
            ],
        ]);

        $provider = $this->provider();
        $provider->setMatchContext(1, ['q' => '*']);

        $result = $provider->getAggregation(
            $this->bucket('price'),
            [],
            100,
            new EntityStorage([])
        );

        // key = floor(value/100)+1: 50→1, 150→2, 199→2.
        $this->assertSame([1 => 2, 2 => 4], $result);
    }

    public function testGetAggregationReturnsEmptyForNonPositiveRange(): void
    {
        // Counts are present, so the assertion proves the `$range <= 0` guard — not an empty
        // result set — is what returns []: without it, `floor(value / 0)` throws DivisionByZeroError.
        $this->client->method('request')->willReturn([
            'facet_counts' => [
                [
                    'field_name' => 'price',
                    'counts' => [
                        ['value' => '50', 'count' => 2],
                    ],
                ],
            ],
        ]);

        $provider = $this->provider();
        $provider->setMatchContext(1, ['q' => '*']);

        $this->assertSame(
            [],
            $provider->getAggregation($this->bucket('price'), [], 0, new EntityStorage([]))
        );
    }

    public function testPrepareDataFormatsRanges(): void
    {
        $data = $this->provider()->prepareData(100, [1 => 5, 2 => 3]);

        $this->assertSame(
            [
                ['from' => 0, 'to' => 100, 'count' => 5],
                ['from' => 100, 'to' => 200, 'count' => 3],
            ],
            $data
        );
    }

    public function testGetIntervalCreatesEngineIntervalWithMatchContext(): void
    {
        $interval = $this->createMock(Interval::class);
        $this->intervalFactory->expects($this->once())
            ->method('create')
            ->with([
                'fieldName' => 'price_0_1',
                'storeId' => 2,
                'matchQuery' => ['q' => 'shoes', 'query_by' => 'name,sku', 'filter_by' => 'category_ids:=5'],
            ])
            ->willReturn($interval);

        $provider = $this->provider();
        $provider->setMatchContext(2, ['q' => 'shoes', 'query_by' => 'name,sku', 'filter_by' => 'category_ids:=5']);

        $result = $provider->getInterval(
            $this->bucket('price'),
            [],
            new EntityStorage([])
        );

        $this->assertSame($interval, $result);
    }

    /**
     * @return BucketInterface&MockObject
     */
    private function bucket(string $field)
    {
        $bucket = $this->createMock(BucketInterface::class);
        $bucket->method('getField')->willReturn($field);

        return $bucket;
    }
}
