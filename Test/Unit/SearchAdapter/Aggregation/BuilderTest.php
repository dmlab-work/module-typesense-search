<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Test\Unit\SearchAdapter\Aggregation;

use DmLab\TypesenseSearch\SearchAdapter\Aggregation\Builder;
use DmLab\TypesenseSearch\SearchAdapter\Dynamic\DataProvider;
use DmLab\TypesenseSearch\SearchAdapter\Schema\CollectionSchema;
use DmLab\TypesenseSearch\SearchAdapter\Schema\DroppedFieldLogger;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Framework\Search\Dynamic\Algorithm\AlgorithmInterface;
use Magento\Framework\Search\Dynamic\Algorithm\Repository as AlgorithmRepository;
use Magento\Framework\Search\Dynamic\DataProviderFactory;
use Magento\Framework\Search\Dynamic\DataProviderInterface;
use Magento\Framework\Search\Dynamic\EntityStorage;
use Magento\Framework\Search\Dynamic\EntityStorageFactory;
use Magento\Framework\Search\Request\Aggregation\DynamicBucket;
use Magento\Framework\Search\Request\Aggregation\TermBucket;
use Magento\Framework\Search\Request\BucketInterface;
use Magento\Framework\Search\RequestInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class BuilderTest extends TestCase
{
    /** @var Builder */
    private Builder $builder;
    /** @var AlgorithmRepository */
    private AlgorithmRepository $algorithmRepository;
    /** @var EntityStorageFactory */
    private EntityStorageFactory $entityStorageFactory;
    /** @var DataProviderFactory */
    private DataProviderFactory $dataProviderFactory;
    /** @var DroppedFieldLogger */
    private DroppedFieldLogger $droppedFieldLogger;

    protected function setUp(): void
    {
        $this->algorithmRepository = $this->createMock(AlgorithmRepository::class);
        $this->entityStorageFactory = $this->createMock(EntityStorageFactory::class);
        $this->dataProviderFactory = $this->createMock(DataProviderFactory::class);
        $this->droppedFieldLogger = $this->createMock(DroppedFieldLogger::class);
        $this->builder = new Builder(
            $this->algorithmRepository,
            $this->entityStorageFactory,
            $this->dataProviderFactory,
            $this->droppedFieldLogger
        );
    }

    /**
     * @param BucketInterface[] $buckets
     */
    private function request(array $buckets): RequestInterface
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getAggregation')->willReturn($buckets);
        $request->method('getDimensions')->willReturn([]);

        return $request;
    }

    private function termBucket(string $name, string $field): TermBucket
    {
        return new TermBucket($name, $field, []);
    }

    private function dynamicBucket(string $name, string $field, string $method = 'auto'): DynamicBucket
    {
        return new DynamicBucket($name, $field, $method);
    }

    /**
     * Wire the dynamic price path: engine data provider, algorithm output. Returns the data provider
     * mock so a test can assert the match context is bound onto it.
     *
     * @param array<int,array<string,mixed>> $items algorithm {from,to,count} ranges
     * @return DataProvider&\PHPUnit\Framework\MockObject\MockObject
     */
    private function stubAlgorithm(array $items, string $method = 'auto')
    {
        $entityStorage = $this->createMock(EntityStorage::class);
        $this->entityStorageFactory->method('create')->willReturn($entityStorage);

        $dataProvider = $this->createMock(DataProvider::class);
        $this->dataProviderFactory->method('create')->willReturn($dataProvider);

        $algorithm = $this->createMock(AlgorithmInterface::class);
        $algorithm->method('getItems')->willReturn($items);
        $this->algorithmRepository->method('get')
            ->with($method, ['dataProvider' => $dataProvider])
            ->willReturn($algorithm);

        return $dataProvider;
    }

    public function testAddAggregationsRequestsFacetByForTermBucketsAndSkipsDynamic(): void
    {
        // Dynamic (price) buckets are computed by the price algorithm, not read from facet_counts,
        // so they must not be faceted — only the term buckets end up in facet_by.
        $params = $this->builder->addAggregations(
            ['q' => '*'],
            $this->request([
                $this->termBucket('brand_bucket', 'brand'),
                $this->termBucket('color_bucket', 'color'),
                $this->dynamicBucket('price_bucket', 'price'),
            ])
        );

        $this->assertSame('brand,color', $params['facet_by']);
        $this->assertSame(10000, $params['max_facet_values']);
        $this->assertSame('*', $params['q']);
    }

    public function testAddAggregationsLeavesParamsUntouchedWithOnlyDynamicBuckets(): void
    {
        $params = $this->builder->addAggregations(
            ['q' => '*'],
            $this->request([$this->dynamicBucket('price_bucket', 'price')])
        );

        $this->assertSame(['q' => '*'], $params);
    }

    public function testAddAggregationsDeduplicatesRepeatedFields(): void
    {
        $params = $this->builder->addAggregations(
            [],
            $this->request([
                $this->termBucket('a', 'brand'),
                $this->termBucket('b', 'brand'),
            ])
        );

        $this->assertSame('brand', $params['facet_by']);
    }

    public function testAddAggregationsLeavesParamsUntouchedWithoutBuckets(): void
    {
        $params = $this->builder->addAggregations(['q' => '*'], $this->request([]));

        $this->assertSame(['q' => '*'], $params);
    }

    public function testAddAggregationsToleratesNullAggregation(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getAggregation')->willReturn(null);

        $this->assertSame(['q' => '*'], $this->builder->addAggregations(['q' => '*'], $request));
    }

    public function testTermFacetsMapToBucketsWithCounts(): void
    {
        $raw = [
            'facet_counts' => [
                [
                    'field_name' => 'brand',
                    'counts' => [
                        ['value' => 'Nike', 'count' => 11],
                        ['value' => 'Puma', 'count' => 4],
                    ],
                ],
            ],
        ];

        $aggregation = $this->builder->build(
            $this->request([$this->termBucket('brand_bucket', 'brand')]),
            $raw
        );

        $bucket = $aggregation->getBucket('brand_bucket');
        $this->assertNotNull($bucket);
        $values = $bucket->getValues();
        $this->assertCount(2, $values);
        $this->assertSame('Nike', $values[0]->getValue());
        $this->assertSame(['value' => 'Nike', 'count' => 11], $values[0]->getMetrics());
        $this->assertSame(['value' => 'Puma', 'count' => 4], $values[1]->getMetrics());
    }

    public function testIntFacetValuesAreTypedAsIntForStrictGraphQlCategoryMatch(): void
    {
        $schema = new CollectionSchema([['name' => 'category_ids', 'type' => 'int32[]', 'facet' => true]]);
        $aggregation = $this->builder->build(
            $this->request([$this->termBucket('category_bucket', 'category_ids')]),
            ['facet_counts' => [['field_name' => 'category_ids', 'counts' => [['value' => '21', 'count' => 5]]]]],
            [],
            0,
            $schema
        );

        $value = $aggregation->getBucket('category_bucket')->getValues()[0];
        self::assertSame(21, $value->getValue());
        self::assertSame(['value' => 21, 'count' => 5], $value->getMetrics());
    }

    public function testLegacyBoolFacetValuesAreTypedAsOneOrZero(): void
    {
        $schema = new CollectionSchema([['name' => 'eco_collection', 'type' => 'bool', 'facet' => true]]);
        $aggregation = $this->builder->build(
            $this->request([$this->termBucket('eco_bucket', 'eco_collection')]),
            ['facet_counts' => [['field_name' => 'eco_collection', 'counts' => [
                ['value' => 'true', 'count' => 3],
                ['value' => 'false', 'count' => 7],
            ]]]],
            [],
            0,
            $schema
        );

        $values = $aggregation->getBucket('eco_bucket')->getValues();
        self::assertSame(1, $values[0]->getValue());
        self::assertSame(['value' => 1, 'count' => 3], $values[0]->getMetrics());
        self::assertSame(0, $values[1]->getValue());
        self::assertSame(['value' => 0, 'count' => 7], $values[1]->getMetrics());
    }

    public function testStringAndUnknownFacetValuesStayStrings(): void
    {
        $schema = new CollectionSchema([['name' => 'brand', 'type' => 'string', 'facet' => true]]);
        $request = $this->request([
            $this->termBucket('brand_bucket', 'brand'),
            $this->termBucket('size_bucket', 'size'),
        ]);
        $raw = ['facet_counts' => [
            ['field_name' => 'brand', 'counts' => [['value' => '42', 'count' => 1]]],
            ['field_name' => 'size', 'counts' => [['value' => '7', 'count' => 2]]],
        ]];

        $typed = $this->builder->build($request, $raw, [], 0, $schema);
        self::assertSame('42', $typed->getBucket('brand_bucket')->getValues()[0]->getValue());
        self::assertSame('7', $typed->getBucket('size_bucket')->getValues()[0]->getValue());

        $untyped = $this->builder->build(
            $this->request([$this->termBucket('category_bucket', 'category_ids')]),
            ['facet_counts' => [['field_name' => 'category_ids', 'counts' => [['value' => '21', 'count' => 5]]]]]
        );
        self::assertSame('21', $untyped->getBucket('category_bucket')->getValues()[0]->getValue());
    }

    public function testDynamicPriceBucketRunsConfiguredAlgorithmIntoRangeValues(): void
    {
        // The match-set query (not the returned page's ids) is bound onto the data provider so the
        // price statistics span the whole result set.
        $dataProvider = $this->stubAlgorithm([
            ['from' => 0, 'to' => 100, 'count' => 3],
            ['from' => 100, 'to' => 200, 'count' => 2],
            ['from' => 200, 'to' => 300, 'count' => 1],
        ]);
        $dataProvider->expects($this->once())
            ->method('setMatchContext')
            ->with(1, ['q' => '*', 'filter_by' => 'category_ids:=5']);

        $values = $this->builder->build(
            $this->request([$this->dynamicBucket('price_bucket', 'price')]),
            [],
            ['q' => '*', 'filter_by' => 'category_ids:=5'],
            1
        )->getBucket('price_bucket')->getValues();

        $this->assertCount(3, $values);
        $this->assertSame('0_100', $values[0]->getValue());
        $this->assertSame(
            ['from' => 0, 'to' => 100, 'count' => 3, 'value' => '0_100'],
            $values[0]->getMetrics()
        );
        $this->assertSame('100_200', $values[1]->getValue());
        $this->assertSame(2, $values[1]->getMetrics()['count']);
        $this->assertSame('200_300', $values[2]->getValue());
        $this->assertSame(1, $values[2]->getMetrics()['count']);
    }

    public function testDynamicPriceBucketSeedsEntityStorageWithMatchedHitIds(): void
    {
        // The framework `Auto` algorithm short-circuits to no ranges on an empty EntityStorage, so
        // the page's hit ids must be handed to the factory even though our data provider ignores them.
        $entityStorage = $this->createMock(EntityStorage::class);
        $this->entityStorageFactory->expects($this->once())
            ->method('create')
            ->with(['10', '20'])
            ->willReturn($entityStorage);

        $dataProvider = $this->createMock(DataProvider::class);
        $this->dataProviderFactory->method('create')->willReturn($dataProvider);

        $algorithm = $this->createMock(AlgorithmInterface::class);
        $algorithm->method('getItems')->willReturn([['from' => 0, 'to' => 50, 'count' => 4]]);
        $this->algorithmRepository->method('get')->willReturn($algorithm);

        $raw = ['hits' => [['document' => ['id' => '10']], ['document' => ['id' => '20']]]];

        $values = $this->builder->build(
            $this->request([$this->dynamicBucket('price_bucket', 'price')]),
            $raw,
            ['q' => '*'],
            1
        )->getBucket('price_bucket')->getValues();

        $this->assertCount(1, $values);
    }

    public function testDynamicPriceBucketHonoursTheBucketMethod(): void
    {
        $this->stubAlgorithm([['from' => 0, 'to' => 50, 'count' => 4]], 'improved');

        $values = $this->builder->build(
            $this->request([$this->dynamicBucket('price_bucket', 'price', 'improved')]),
            [],
            ['q' => '*'],
            1
        )->getBucket('price_bucket')->getValues();

        $this->assertCount(1, $values);
        $this->assertSame('0_50', $values[0]->getValue());
    }

    public function testDynamicPriceBucketEmptyWhenAlgorithmReturnsNoRanges(): void
    {
        $this->stubAlgorithm([]);

        $values = $this->builder->build(
            $this->request([$this->dynamicBucket('price_bucket', 'price')]),
            [],
            ['q' => '*'],
            1
        )->getBucket('price_bucket')->getValues();

        $this->assertSame([], $values);
    }

    public function testDynamicPriceBucketEmptyWhenNoMatchQuery(): void
    {
        // A degraded/unsupported request (empty match query) must not run the price algorithm — the
        // bucket is empty rather than computing stats over the whole catalog.
        $this->dataProviderFactory->expects($this->never())->method('create');

        $values = $this->builder->build(
            $this->request([$this->dynamicBucket('price_bucket', 'price')]),
            []
        )->getBucket('price_bucket')->getValues();

        $this->assertSame([], $values);
    }

    public function testDynamicPriceBucketDegradesToEmptyOnAlgorithmError(): void
    {
        $this->entityStorageFactory->method('create')->willReturn($this->createMock(EntityStorage::class));
        $this->dataProviderFactory->method('create')->willReturn($this->createMock(DataProviderInterface::class));
        $this->algorithmRepository->method('get')
            ->willThrowException(new LocalizedException(new Phrase('unknown algorithm')));

        $values = $this->builder->build(
            $this->request([$this->dynamicBucket('price_bucket', 'price')]),
            [],
            ['q' => '*'],
            1
        )->getBucket('price_bucket')->getValues();

        $this->assertSame([], $values);
    }

    public function testMissingFacetFieldYieldsEmptyBucketWithoutFatal(): void
    {
        $this->stubAlgorithm([]);

        $aggregation = $this->builder->build(
            $this->request([
                $this->termBucket('brand_bucket', 'brand'),
                $this->dynamicBucket('price_bucket', 'price'),
            ]),
            ['facet_counts' => []],
            ['q' => '*'],
            1
        );

        $this->assertNotNull($aggregation->getBucket('brand_bucket'));
        $this->assertSame([], $aggregation->getBucket('brand_bucket')->getValues());
        $this->assertNotNull($aggregation->getBucket('price_bucket'));
        $this->assertSame([], $aggregation->getBucket('price_bucket')->getValues());
    }

    public function testEmptyRawResponseYieldsBucketsWithNoValues(): void
    {
        $aggregation = $this->builder->build(
            $this->request([$this->termBucket('brand_bucket', 'brand')]),
            []
        );

        $this->assertSame([], $aggregation->getBucket('brand_bucket')->getValues());
    }

    public function testAddAggregationsSkipsFieldsTheSchemaDoesNotFacet(): void
    {
        $logged = [];
        $this->droppedFieldLogger->expects($this->exactly(2))->method('log')
            ->willReturnCallback(static function (string $alias, string $field) use (&$logged): void {
                $logged[] = $alias . ':' . $field;
            });
        $schema = new CollectionSchema([
            ['name' => 'color', 'type' => 'int32[]', 'facet' => true],
            ['name' => 'description', 'type' => 'string'],
        ], 'alias_1');
        $request = $this->request([
            $this->termBucket('color_bucket', 'color'),
            $this->termBucket('description_bucket', 'description'),
            $this->termBucket('activity_bucket', 'activity'),
        ]);

        $params = $this->builder->addAggregations(['q' => '*'], $request, $schema);
        self::assertSame('color', $params['facet_by']);
        self::assertSame(['alias_1:description', 'alias_1:activity'], $logged);

        $aggregation = $this->builder->build($request, ['facet_counts' => [
            ['field_name' => 'color', 'counts' => [['value' => '49', 'count' => 2]]],
        ]]);
        self::assertCount(1, $aggregation->getBucket('color_bucket')->getValues());
        self::assertSame([], $aggregation->getBucket('description_bucket')->getValues());
        self::assertSame([], $aggregation->getBucket('activity_bucket')->getValues());
    }

    public function testAddAggregationsWithOnlyUnfacetedFieldsLeavesParamsUntouched(): void
    {
        $params = $this->builder->addAggregations(
            ['q' => '*'],
            $this->request([$this->termBucket('description_bucket', 'description')]),
            new CollectionSchema([['name' => 'description', 'type' => 'string']])
        );

        self::assertSame(['q' => '*'], $params);
    }

    public function testNoBucketsYieldsEmptyAggregation(): void
    {
        $aggregation = $this->builder->build($this->request([]), ['facet_counts' => []]);

        $this->assertSame([], $aggregation->getBuckets());
    }
}
