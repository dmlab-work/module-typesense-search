<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseSearch\Test\Unit\SearchAdapter\Aggregation;

use MageDevGroup\TypesenseCore\Exception\TypesenseException;
use MageDevGroup\TypesenseCore\Model\Client\TypesenseClient;
use MageDevGroup\TypesenseIndexer\Api\IndexNameResolverInterface;
use MageDevGroup\TypesenseSearch\SearchAdapter\Aggregation\Interval;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class IntervalTest extends TestCase
{
    /** @var TypesenseClient&MockObject */
    private $client;
    /** @var IndexNameResolverInterface&MockObject */
    private $indexNameResolver;
    /** @var LoggerInterface&MockObject */
    private $logger;

    protected function setUp(): void
    {
        $this->client = $this->createMock(TypesenseClient::class);
        $this->indexNameResolver = $this->createMock(IndexNameResolverInterface::class);
        $this->indexNameResolver->method('getAliasName')->willReturn('alias');
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    /**
     * @param array<string,mixed> $matchQuery
     */
    private function interval(array $matchQuery = ['q' => '*', 'filter_by' => 'category_ids:=5']): Interval
    {
        return new Interval($this->client, $this->indexNameResolver, $this->logger, 'price', 1, $matchQuery);
    }

    private function hits(array $prices): array
    {
        return ['hits' => array_map(static fn ($p) => ['document' => ['price' => $p]], $prices)];
    }

    public function testLoadQueriesSortedPriceWindowAndReturnsFloats(): void
    {
        // The match-set filter is ANDed with the price bounds, so the interval walks only the
        // result set's price axis — not the whole catalog.
        $this->client->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                '/collections/alias/documents/search',
                null,
                [
                    'q' => '*',
                    'filter_by' => '(category_ids:=5) && price:>=9.995 && price:<19.995',
                    'sort_by' => 'price:asc',
                    'include_fields' => 'price',
                    'limit' => 5,
                    'offset' => 0,
                ]
            )
            ->willReturn($this->hits([10, 15, 18]));

        $this->assertSame([10.0, 15.0, 18.0], $this->interval()->load(5, 0, 10, 20));
    }

    public function testLoadCarriesTextSearchQuery(): void
    {
        // A price slider on a search-results page must respect the text query, so `q`/`query_by`
        // ride along with the match filter.
        $this->client->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                $this->anything(),
                null,
                $this->callback(static function (array $params): bool {
                    return $params['q'] === 'shoes'
                        && $params['query_by'] === 'name,sku'
                        && $params['filter_by'] === '(in_stock:=true) && price:>=0 && price:<0';
                })
            )
            ->willReturn($this->hits([]));

        $matchQuery = ['q' => 'shoes', 'query_by' => 'name,sku', 'filter_by' => 'in_stock:=true'];
        $this->assertSame([], $this->interval($matchQuery)->load(5));
    }

    public function testLoadWithoutBoundsUsesEmptyRange(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                $this->anything(),
                null,
                $this->callback(static function (array $params): bool {
                    return $params['filter_by'] === '(category_ids:=5) && price:>=0 && price:<0';
                })
            )
            ->willReturn($this->hits([]));

        $this->assertSame([], $this->interval()->load(5));
    }

    public function testLoadWithoutMatchFilterUsesPriceBoundsOnly(): void
    {
        // No match filter (e.g. store-wide, no active filters) → the interval is bounded by price
        // alone, defaulting `q` to `*`.
        $this->client->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                $this->anything(),
                null,
                $this->callback(static function (array $params): bool {
                    return $params['q'] === '*' && $params['filter_by'] === 'price:>=0.995';
                })
            )
            ->willReturn($this->hits([5]));

        $this->assertSame([5.0], $this->interval(['q' => '*'])->load(5, 0, 1));
    }

    public function testLoadPreviousReturnsFalseWhenNothingBelow(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->willReturn(['found' => 0]);

        $this->assertFalse($this->interval()->loadPrevious(20.0, 3, 10));
    }

    public function testLoadPreviousLoadsWindowWhenItemsExist(): void
    {
        $matcher = $this->exactly(2);
        $this->client->expects($matcher)
            ->method('request')
            ->willReturnCallback(function (string $method, string $path, $body, array $params) use ($matcher) {
                if ($matcher->numberOfInvocations() === 1) {
                    $this->assertSame(0, $params['per_page']);

                    return ['found' => 2];
                }

                $this->assertSame(1, $params['limit']);
                $this->assertSame(1, $params['offset']);

                return $this->hits([12]);
            });

        $this->assertSame([12.0], $this->interval()->loadPrevious(20.0, 2, null));
    }

    public function testLoadNextReturnsFalseWhenNothingAbove(): void
    {
        $this->client->expects($this->once())
            ->method('request')
            ->willReturn(['found' => 0]);

        $this->assertFalse($this->interval()->loadNext(20.0, 5, null));
    }

    public function testLoadNextReturnsReversedPrices(): void
    {
        $matcher = $this->exactly(2);
        $this->client->expects($matcher)
            ->method('request')
            ->willReturnCallback(function (string $method, string $path, $body, array $params) use ($matcher) {
                if ($matcher->numberOfInvocations() === 1) {
                    return ['found' => 2];
                }

                return $this->hits([30, 40]);
            });

        $this->assertSame([40.0, 30.0], $this->interval()->loadNext(20.0, 4, null));
    }

    public function testLoadPagesWindowsLargerThanTheCap(): void
    {
        $matcher = $this->exactly(2);
        $this->client->expects($matcher)
            ->method('request')
            ->willReturnCallback(function (string $method, string $path, $body, array $params) use ($matcher) {
                if ($matcher->numberOfInvocations() === 1) {
                    $this->assertSame(250, $params['limit']);
                    $this->assertSame(0, $params['offset']);

                    return $this->hits(array_fill(0, 250, 5));
                }

                $this->assertSame(50, $params['limit']);
                $this->assertSame(250, $params['offset']);

                return $this->hits(array_fill(0, 50, 9));
            });

        $this->assertCount(300, $this->interval()->load(300, 0, 10, 20));
    }

    public function testLoadDegradesToEmptyOnTypesenseFailure(): void
    {
        $this->client->method('request')->willThrowException(new TypesenseException('down'));
        $this->logger->expects($this->once())->method('critical');

        $this->assertSame([], $this->interval()->load(5, 0, 10, 20));
    }

    public function testCountDegradesToFalseWindowOnTypesenseFailure(): void
    {
        $this->client->method('request')->willThrowException(new TypesenseException('down'));
        $this->logger->expects($this->exactly(2))->method('critical');

        $this->assertFalse($this->interval()->loadPrevious(20.0, 3, 10));
        $this->assertFalse($this->interval()->loadNext(20.0, 5, null));
    }
}
