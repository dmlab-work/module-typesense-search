<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Test\Unit\SearchAdapter\Query;

use DmLab\TypesenseIndexer\Api\SearchableFieldsProviderInterface;
use DmLab\TypesenseSearch\Exception\UnsupportedSearchRequestException;
use DmLab\TypesenseSearch\SearchAdapter\Field\FieldNameResolver;
use DmLab\TypesenseSearch\SearchAdapter\Query\QueryBuilder;
use DmLab\TypesenseSearch\SearchAdapter\StoreResolver;
use Magento\Framework\App\ScopeInterface;
use Magento\Framework\App\ScopeResolverInterface;
use Magento\Framework\Search\Request\Dimension;
use Magento\Framework\Search\Request\Filter\BoolExpression as FilterBool;
use Magento\Framework\Search\Request\Filter\Range as RangeFilter;
use Magento\Framework\Search\Request\Filter\Term as TermFilter;
use Magento\Framework\Search\Request\Filter\Wildcard as WildcardFilter;
use Magento\Framework\Search\Request\FilterInterface;
use Magento\Framework\Search\Request\Query\BoolExpression as QueryBool;
use Magento\Framework\Search\Request\Query\Filter as FilterQuery;
use Magento\Framework\Search\Request\Query\MatchQuery;
use Magento\Framework\Search\Request\QueryInterface;
use Magento\Framework\Search\RequestInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class QueryBuilderTest extends TestCase
{
    private const FIELDS = ['name' => 5, 'sku' => 3];

    private function builder(array $fields = self::FIELDS, int $storeId = 1): QueryBuilder
    {
        $provider = $this->createMock(SearchableFieldsProviderInterface::class);
        $provider->method('get')->with($storeId)->willReturn($fields);

        $scope = $this->createMock(ScopeInterface::class);
        $scope->method('getId')->willReturn($storeId);
        $scopeResolver = $this->createMock(ScopeResolverInterface::class);
        $scopeResolver->method('getScope')->willReturn($scope);

        // Stand in for the real resolver: context-scoped codes fan out to their document field,
        // everything else maps 1:1 — the exact contract QueryBuilder relies on.
        $fieldNameResolver = $this->createMock(FieldNameResolver::class);
        $fieldNameResolver->method('resolve')->willReturnCallback(
            static fn (string $field, array $context = []): string => match ($field) {
                'price' => 'price_0_1',
                'position' => 'position_category_' . ($context['categoryId'] ?? 0),
                default => $field,
            }
        );

        return new QueryBuilder($provider, new StoreResolver($scopeResolver), $fieldNameResolver);
    }

    private function request(QueryInterface $query, ?int $from = null, ?int $size = null): RequestInterface
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getQuery')->willReturn($query);
        $request->method('getDimensions')->willReturn([new Dimension('scope', '1')]);
        $request->method('getFrom')->willReturn($from);
        $request->method('getSize')->willReturn($size);

        return $request;
    }

    private function filterQuery(FilterInterface $filter): FilterQuery
    {
        return new FilterQuery('f', null, FilterQuery::REFERENCE_FILTER, $filter);
    }

    public function testQueryByAndWeightsComeFromIndexerContract(): void
    {
        $payload = $this->builder()->build($this->request(new MatchQuery('search', 'shoes', null, [])));

        self::assertSame('name,sku', $payload['query_by']);
        self::assertSame('5,3', $payload['query_by_weights']);
        self::assertSame('shoes', $payload['q']);
    }

    public function testWeightsAreNotReDerivedButTakenAsProvided(): void
    {
        $payload = $this->builder(['description' => 1, 'name' => 10])
            ->build($this->request(new MatchQuery('search', 'x', null, [])));

        self::assertSame('description,name', $payload['query_by']);
        self::assertSame('1,10', $payload['query_by_weights']);
    }

    public function testEmptyMatchProducesWildcard(): void
    {
        $payload = $this->builder()->build($this->request(new MatchQuery('search', '', null, [])));

        self::assertSame('*', $payload['q']);
        self::assertArrayNotHasKey('filter_by', $payload);
    }

    public function testWildcardMatchKeepsAllSearchableFields(): void
    {
        // Quick search targets `field="*"` — the term must search every searchable field.
        $payload = $this->builder()->build(
            $this->request(new MatchQuery('search', 'shoes', null, [['field' => '*']]))
        );

        self::assertSame('name,sku', $payload['query_by']);
        self::assertSame('5,3', $payload['query_by_weights']);
    }

    public function testFieldScopedMatchNarrowsQueryBy(): void
    {
        // Advanced Search emits one match per attribute, scoped to that attribute's field: the term
        // must not leak into other searchable fields.
        $payload = $this->builder()->build(
            $this->request(new MatchQuery('name_query', 'shoes', null, [['field' => 'name', 'boost' => 5]]))
        );

        self::assertSame('name', $payload['query_by']);
        self::assertSame('5', $payload['query_by_weights']);
        self::assertSame('shoes', $payload['q']);
    }

    public function testFieldScopedMatchOnNonSearchableFieldDropsQueryBy(): void
    {
        // A match scoped to a field the indexer never marked searchable cannot back a `query_by`;
        // it drops out (the adapter then degrades to an empty response) rather than searching all.
        $payload = $this->builder()->build(
            $this->request(new MatchQuery('color_query', 'red', null, [['field' => 'color']]))
        );

        self::assertSame('', $payload['query_by']);
        self::assertSame('red', $payload['q']);
    }

    public function testMultipleFieldScopedMatchesUnionTheirFields(): void
    {
        $query = new QueryBool(
            'advanced',
            null,
            [
                new MatchQuery('name_query', 'shoes', null, [['field' => 'name']]),
                new MatchQuery('sku_query', 'ABC', null, [['field' => 'sku']]),
            ]
        );

        $payload = $this->builder()->build($this->request($query));

        self::assertSame('name,sku', $payload['query_by']);
        self::assertSame('5,3', $payload['query_by_weights']);
    }

    public function testTermFilterStringValueIsBacktickWrapped(): void
    {
        $query = $this->filterQuery(new TermFilter('t', 'Nike', 'brand'));

        $payload = $this->builder()->build($this->request($query));

        self::assertSame('brand:=`Nike`', $payload['filter_by']);
        self::assertSame('*', $payload['q']);
    }

    public function testTermFilterNumericValueIsBare(): void
    {
        $query = $this->filterQuery(new TermFilter('t', '5', 'category_ids'));

        $payload = $this->builder()->build($this->request($query));

        self::assertSame('category_ids:=5', $payload['filter_by']);
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function canonicalNumberProvider(): array
    {
        return [
            'integer' => ['12', 'sku:=12'],
            'decimal' => ['12.5', 'sku:=12.5'],
            'negative' => ['-3', 'sku:=-3'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('canonicalNumberProvider')]
    public function testCanonicalNumberStaysBare(string $value, string $expected): void
    {
        $payload = $this->builder()->build($this->request($this->filterQuery(new TermFilter('t', $value, 'sku'))));

        self::assertSame($expected, $payload['filter_by']);
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function numericLookingStringProvider(): array
    {
        return [
            'leading zeros' => ['007', 'sku:=`007`'],
            'exponent' => ['1e3', 'sku:=`1e3`'],
            'leading space' => [' 12', 'sku:=` 12`'],
            'leading plus' => ['+5', 'sku:=`+5`'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('numericLookingStringProvider')]
    public function testNumericLookingStringIsQuoted(string $value, string $expected): void
    {
        // is_numeric() is true for these, but they target a string field — emitting them bare would
        // miss the match, so they must be backtick-quoted like any other string value.
        $payload = $this->builder()->build($this->request($this->filterQuery(new TermFilter('t', $value, 'sku'))));

        self::assertSame($expected, $payload['filter_by']);
    }

    public function testHostileResolvedFieldNameIsRejected(): void
    {
        // Field names come from Magento attribute codes (safe); a name that escapes `[a-z0-9_]`
        // cannot be a real schema field and must be rejected rather than interpolated into filter_by.
        $query = $this->filterQuery(new TermFilter('t', 'x', 'brand:=`y` || id'));

        $this->expectException(UnsupportedSearchRequestException::class);
        $this->builder()->build($this->request($query));
    }

    public function testAdversarialFullTextQueryIsPassedThroughInert(): void
    {
        // `q` is plain full-text, not grammar — backticks/operators in it are inert and pass through.
        $payload = $this->builder()->build(
            $this->request(new MatchQuery('search', 'shoes `|| id:=1', null, []))
        );

        self::assertSame('shoes `|| id:=1', $payload['q']);
        self::assertArrayNotHasKey('filter_by', $payload);
    }

    public function testTermFilterArrayValue(): void
    {
        $query = $this->filterQuery(new TermFilter('t', ['5', '7'], 'category_ids'));

        $payload = $this->builder()->build($this->request($query));

        self::assertSame('category_ids:=[5,7]', $payload['filter_by']);
    }

    public function testTermFilterBooleanValueIsBare(): void
    {
        $true = $this->builder()->build($this->request($this->filterQuery(new TermFilter('t', true, 'in_stock'))));
        self::assertSame('in_stock:=true', $true['filter_by']);

        $false = $this->builder()->build($this->request($this->filterQuery(new TermFilter('t', false, 'in_stock'))));
        self::assertSame('in_stock:=false', $false['filter_by']);
    }

    public function testRangeFilterBothBoundsResolvesScopedPriceField(): void
    {
        $query = $this->filterQuery(new RangeFilter('r', 'price', 10, 100));

        $payload = $this->builder()->build($this->request($query));

        self::assertSame('(price_0_1:>=10 && price_0_1:<=100)', $payload['filter_by']);
    }

    public function testRangeFilterOpenFrom(): void
    {
        $query = $this->filterQuery(new RangeFilter('r', 'price', null, 100));

        $payload = $this->builder()->build($this->request($query));

        self::assertSame('price_0_1:<=100', $payload['filter_by']);
    }

    public function testRangeFilterOpenTo(): void
    {
        $query = $this->filterQuery(new RangeFilter('r', 'price', 10, null));

        $payload = $this->builder()->build($this->request($query));

        self::assertSame('price_0_1:>=10', $payload['filter_by']);
    }

    public function testBoolQueryMustShouldMustNot(): void
    {
        $must = [$this->filterQuery(new TermFilter('t', 'Nike', 'brand'))];
        $should = [
            $this->filterQuery(new TermFilter('t', 'red', 'color')),
            $this->filterQuery(new TermFilter('t', 'blue', 'color')),
        ];
        $not = [$this->filterQuery(new TermFilter('t', 'disabled', 'status'))];
        $query = new QueryBool('bool', null, $must, $should, $not);

        $payload = $this->builder()->build($this->request($query));

        self::assertSame(
            'brand:=`Nike` && status:!=`disabled` && (color:=`red` || color:=`blue`)',
            $payload['filter_by']
        );
    }

    public function testMatchAndFilterCombined(): void
    {
        $query = new QueryBool(
            'bool',
            null,
            [
                new MatchQuery('search', 'running shoes', null, []),
                $this->filterQuery(new TermFilter('t', '4', 'visibility')),
            ]
        );

        $payload = $this->builder()->build($this->request($query));

        self::assertSame('running shoes', $payload['q']);
        self::assertSame('visibility:=4', $payload['filter_by']);
    }

    public function testNestedBoolFilter(): void
    {
        $inner = new FilterBool(
            'b',
            [new TermFilter('t', 'Nike', 'brand')],
            [new TermFilter('t', 'red', 'color')],
            [new TermFilter('t', 'disabled', 'status')]
        );
        $query = $this->filterQuery($inner);

        $payload = $this->builder()->build($this->request($query));

        self::assertSame(
            'brand:=`Nike` && status:!=`disabled` && color:=`red`',
            $payload['filter_by']
        );
    }

    public function testEmptyBoolFilterProducesNoFilter(): void
    {
        $query = $this->filterQuery(new FilterBool('b', [], [], []));

        $payload = $this->builder()->build($this->request($query));

        self::assertArrayNotHasKey('filter_by', $payload);
        self::assertSame('*', $payload['q']);
    }

    public function testNestedEmptyBoolFilterIsSkipped(): void
    {
        $inner = new FilterBool('inner', [], [], []);
        $query = $this->filterQuery(
            new FilterBool('b', [new TermFilter('t', 'Nike', 'brand'), $inner], [], [])
        );

        $payload = $this->builder()->build($this->request($query));

        self::assertSame('brand:=`Nike`', $payload['filter_by']);
    }

    public function testFilterReferenceToQuery(): void
    {
        $inner = new QueryBool('bool', null, [$this->filterQuery(new TermFilter('t', 'Nike', 'brand'))]);
        $query = new FilterQuery('f', null, FilterQuery::REFERENCE_QUERY, $inner);

        $payload = $this->builder()->build($this->request($query));

        self::assertSame('brand:=`Nike`', $payload['filter_by']);
    }

    public function testValueWithSpacesIsWrapped(): void
    {
        $query = $this->filterQuery(new TermFilter('t', 'Air Max', 'name'));

        $payload = $this->builder()->build($this->request($query));

        self::assertSame('name:=`Air Max`', $payload['filter_by']);
    }

    public function testValueWithBacktickIsStripped(): void
    {
        $query = $this->filterQuery(new TermFilter('t', 'a`b', 'name'));

        $payload = $this->builder()->build($this->request($query));

        self::assertSame('name:=`ab`', $payload['filter_by']);
    }

    public function testPaginationFromAndSize(): void
    {
        $payload = $this->builder()->build(
            $this->request(new MatchQuery('search', 'x', null, []), 20, 10)
        );

        self::assertSame(10, $payload['per_page']);
        self::assertSame(3, $payload['page']);
    }

    public function testNoPaginationWhenSizeMissing(): void
    {
        $payload = $this->builder()->build($this->request(new MatchQuery('search', 'x', null, [])));

        self::assertArrayNotHasKey('per_page', $payload);
        self::assertArrayNotHasKey('page', $payload);
    }

    public function testSortBuiltFromRequest(): void
    {
        $request = new class(new MatchQuery('search', 'x', null, [])) implements RequestInterface {
            public function __construct(private readonly QueryInterface $query)
            {
            }

            public function getName()
            {
                return 'req';
            }

            public function getIndex()
            {
                return 'catalogsearch_fulltext';
            }

            public function getDimensions()
            {
                return [new Dimension('scope', '1')];
            }

            public function getAggregation()
            {
                return [];
            }

            public function getQuery()
            {
                return $this->query;
            }

            public function getFrom()
            {
                return 0;
            }

            public function getSize()
            {
                return 10;
            }

            public function getSort()
            {
                return [
                    ['field' => 'relevance', 'direction' => 'DESC'],
                    ['field' => 'price', 'direction' => 'asc'],
                ];
            }
        };

        $payload = $this->builder()->build($request);

        self::assertSame('_text_match:desc,price_0_1:asc', $payload['sort_by']);
    }

    public function testEntityIdTiebreakerSortIsDropped(): void
    {
        // Magento's Fulltext\Collection always appends an `entity_id` order; Typesense has no
        // sortable field for it, so it must be stripped rather than emitted verbatim (which 404s).
        $request = new class(new MatchQuery('search', 'x', null, [])) implements RequestInterface {
            public function __construct(private readonly QueryInterface $query)
            {
            }

            public function getName()
            {
                return 'req';
            }

            public function getIndex()
            {
                return 'catalogsearch_fulltext';
            }

            public function getDimensions()
            {
                return [new Dimension('scope', '1')];
            }

            public function getAggregation()
            {
                return [];
            }

            public function getQuery()
            {
                return $this->query;
            }

            public function getFrom()
            {
                return 0;
            }

            public function getSize()
            {
                return 10;
            }

            public function getSort()
            {
                return [
                    ['field' => 'price', 'direction' => 'asc'],
                    ['field' => 'entity_id', 'direction' => 'desc'],
                ];
            }
        };

        $payload = $this->builder()->build($request);

        self::assertSame('price_0_1:asc', $payload['sort_by']);
    }

    public function testEntityIdOnlySortYieldsNoSortKey(): void
    {
        $request = new class(new MatchQuery('search', 'x', null, [])) implements RequestInterface {
            public function __construct(private readonly QueryInterface $query)
            {
            }

            public function getName()
            {
                return 'req';
            }

            public function getIndex()
            {
                return 'catalogsearch_fulltext';
            }

            public function getDimensions()
            {
                return [new Dimension('scope', '1')];
            }

            public function getAggregation()
            {
                return [];
            }

            public function getQuery()
            {
                return $this->query;
            }

            public function getFrom()
            {
                return 0;
            }

            public function getSize()
            {
                return 10;
            }

            public function getSort()
            {
                return [['field' => 'entity_id', 'direction' => 'desc']];
            }
        };

        $payload = $this->builder()->build($request);

        self::assertArrayNotHasKey('sort_by', $payload);
    }

    public function testPositionSortResolvesToTheBrowsedCategoryField(): void
    {
        $category = $this->filterQuery(new TermFilter('t', '7', 'category_ids'));
        $query = new QueryBool(
            'bool',
            null,
            ['category' => $category, new MatchQuery('search', 'x', null, [])]
        );

        $request = new class($query) implements RequestInterface {
            public function __construct(private readonly QueryInterface $query)
            {
            }

            public function getName()
            {
                return 'req';
            }

            public function getIndex()
            {
                return 'catalogsearch_fulltext';
            }

            public function getDimensions()
            {
                return [new Dimension('scope', '1')];
            }

            public function getAggregation()
            {
                return [];
            }

            public function getQuery()
            {
                return $this->query;
            }

            public function getFrom()
            {
                return 0;
            }

            public function getSize()
            {
                return 10;
            }

            public function getSort()
            {
                return [['field' => 'position', 'direction' => 'asc']];
            }
        };

        $payload = $this->builder()->build($request);

        self::assertSame('position_category_7:asc', $payload['sort_by']);
    }

    public function testNoSortKeyWhenRequestHasNoSort(): void
    {
        $payload = $this->builder()->build($this->request(new MatchQuery('search', 'x', null, [])));

        self::assertArrayNotHasKey('sort_by', $payload);
    }

    public function testWildcardFilterThrows(): void
    {
        $query = $this->filterQuery(new WildcardFilter('w', 'abc', 'sku'));

        $this->expectException(UnsupportedSearchRequestException::class);
        $this->builder()->build($this->request($query));
    }

    public function testNegatedRangeThrows(): void
    {
        $query = new QueryBool('bool', null, [], [], [$this->filterQuery(new RangeFilter('r', 'price', 1, 2))]);

        $this->expectException(UnsupportedSearchRequestException::class);
        $this->builder()->build($this->request($query));
    }

    public function testUnknownQueryTypeThrows(): void
    {
        $query = $this->createMock(QueryInterface::class);
        $query->method('getType')->willReturn('somethingElse');

        $this->expectException(UnsupportedSearchRequestException::class);
        $this->builder()->build($this->request($query));
    }
}
