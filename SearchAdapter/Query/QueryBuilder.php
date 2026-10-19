<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\SearchAdapter\Query;

use DmLab\TypesenseIndexer\Api\IndexNameResolverInterface;
use DmLab\TypesenseIndexer\Api\SearchableFieldsProviderInterface;
use DmLab\TypesenseSearch\Exception\UnsupportedSearchRequestException;
use DmLab\TypesenseSearch\SearchAdapter\Field\FieldNameResolver;
use DmLab\TypesenseSearch\SearchAdapter\Schema\CollectionSchema;
use DmLab\TypesenseSearch\SearchAdapter\Schema\DroppedFieldLogger;
use DmLab\TypesenseSearch\SearchAdapter\StoreResolver;
use Magento\CatalogSearch\Model\Indexer\Fulltext;
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

/**
 * Translates a Magento {@see RequestInterface} into a Typesense search payload.
 *
 * Separation of concerns Typesense makes explicit and ES blurs:
 *  - full-text `matchQuery` values become the single `q` string;
 *  - `query_by` / `query_by_weights` are `typesense-indexer`'s {@see SearchableFieldsProviderInterface}
 *    set (string-only-safe, never re-derived here), narrowed to the fields a match scopes to — a
 *    quick-search `*` match keeps every field; an Advanced Search attribute match keeps only its own;
 *  - every other constraint (term/range/bool filters) becomes a `filter_by` expression, its values
 *    typed by the alias schema the adapter passes in; a null schema keeps untyped values.
 *
 * Constructs Typesense cannot express (negated wildcards/compounds, unknown types)
 * raise {@see UnsupportedSearchRequestException} rather than silently dropping the constraint.
 */
class QueryBuilder
{
    /**
     * @param SearchableFieldsProviderInterface $searchableFieldsProvider indexer-owned `query_by` seam
     * @param StoreResolver $storeResolver resolves the request dimension to a store id
     * @param FieldNameResolver $fieldNameResolver maps context-scoped codes (price/position) to their doc field
     * @param IndexNameResolverInterface $indexNameResolver indexer-owned alias name authority
     * @param FilterValueFormatter $valueFormatter type-aware filter value formatting
     * @param SynonymSetResolver $synonymSetResolver store's native-synonym set, if any
     * @param DroppedFieldLogger $droppedFieldLogger
     */
    public function __construct(
        private readonly SearchableFieldsProviderInterface $searchableFieldsProvider,
        private readonly StoreResolver $storeResolver,
        private readonly FieldNameResolver $fieldNameResolver,
        private readonly IndexNameResolverInterface $indexNameResolver,
        private readonly FilterValueFormatter $valueFormatter,
        private readonly SynonymSetResolver $synonymSetResolver,
        private readonly DroppedFieldLogger $droppedFieldLogger
    ) {
    }

    /**
     * Build the Typesense search parameters for a request (collection name is added downstream).
     *
     * @param RequestInterface $request
     * @param CollectionSchema|null $schema alias schema, null when unknown
     * @return array<string,mixed>
     * @throws UnsupportedSearchRequestException
     */
    public function build(RequestInterface $request, ?CollectionSchema $schema): array
    {
        $storeId = $this->storeResolver->resolve($request);
        $searchableFields = $this->searchableFieldsProvider->get($storeId);
        $alias = $this->indexNameResolver->getAliasName(Fulltext::INDEXER_ID, $storeId);

        $textTerms = [];
        $matchFields = [];
        $filterBy = $this->processQuery($request->getQuery(), $textTerms, $matchFields, false, $schema);

        $queryByFields = $this->resolveQueryByFields($searchableFields, $matchFields);

        $payload = [
            'q' => $textTerms === [] ? '*' : implode(' ', array_keys($textTerms)),
            'query_by' => implode(',', array_keys($queryByFields)),
            'query_by_weights' => implode(',', array_values($queryByFields)),
        ];

        if ($filterBy !== null && $filterBy !== '') {
            $payload['filter_by'] = $filterBy;
        }

        $sortBy = $this->buildSort($request, $schema, $alias);
        if ($sortBy !== '') {
            $payload['sort_by'] = $sortBy;
        }

        $size = (int)$request->getSize();
        if ($size > 0) {
            $payload['per_page'] = $size;
            $payload['page'] = intdiv(max(0, (int)$request->getFrom()), $size) + 1;
        }

        $synonymSet = $this->synonymSetResolver->getSetName($storeId);
        if ($synonymSet !== null) {
            $payload['synonym_sets'] = $synonymSet;
        }

        return $payload;
    }

    /**
     * Walk a request query node: match → `q`, bool/filter → `filter_by` fragment (or null).
     *
     * @param QueryInterface $query
     * @param array<string,true> $textTerms collected match values, by reference
     * @param array<string,true> $matchFields fields a match scopes to (`*` = all), by reference
     * @param bool $negate
     * @param CollectionSchema|null $schema
     * @throws UnsupportedSearchRequestException
     */
    private function processQuery(
        QueryInterface $query,
        array &$textTerms,
        array &$matchFields,
        bool $negate,
        ?CollectionSchema $schema
    ): ?string {
        switch ($query->getType()) {
            case QueryInterface::TYPE_MATCH:
                if ($negate) {
                    throw new UnsupportedSearchRequestException(
                        'Typesense cannot express a negated full-text match in filter_by.'
                    );
                }
                /** @var MatchQuery $query */
                $value = trim((string)$query->getValue());
                if ($value !== '') {
                    $textTerms[$value] = true;
                    $this->collectMatchFields($query, $matchFields);
                }

                return null;
            case QueryInterface::TYPE_BOOL:
                if ($negate) {
                    throw new UnsupportedSearchRequestException(
                        'Typesense filter_by cannot negate a compound boolean query.'
                    );
                }
                /** @var QueryBool $query */
                return $this->processBoolQuery($query, $textTerms, $matchFields, $schema);
            case QueryInterface::TYPE_FILTER:
                /** @var FilterQuery $query */
                return $this->processFilterQuery($query, $textTerms, $matchFields, $negate, $schema);
            default:
                throw new UnsupportedSearchRequestException(
                    sprintf('Unsupported query type "%s".', $query->getType())
                );
        }
    }

    /**
     * Record the fields a match query scopes its term to.
     *
     * Magento's quick-search match targets `field="*"` (all fields); the `RequestGenerator` emits
     * one match per text/varchar attribute in Advanced Search, scoped to that single attribute code
     * ({@see \Magento\Catalog\Search\...\RequestGenerator::generateAdvancedSearchRequest()}). `*`
     * (or no declared match) means "search every searchable field"; a named field narrows `query_by`
     * so an attribute search does not leak the term into unrelated fields.
     *
     * @param MatchQuery $query
     * @param array<string,true> $matchFields by reference
     */
    private function collectMatchFields(MatchQuery $query, array &$matchFields): void
    {
        $matches = $query->getMatches();
        if ($matches === []) {
            $matchFields['*'] = true;

            return;
        }

        foreach ($matches as $match) {
            $field = (string)($match['field'] ?? '');
            $matchFields[$field === '' ? '*' : $field] = true;
        }
    }

    /**
     * Narrow the searchable `query_by` set to the fields the request's matches scoped to.
     *
     * With a `*` match (quick search) or no match at all (pure filter/browse request) every
     * searchable field stays in `query_by`. Otherwise only the named match fields are kept —
     * resolved to their document field (or its `<field>_value` label field, which is how the indexer
     * makes searchable selects searchable) and intersected with the searchable set, so a non-searchable
     * or unknown attribute drops out (an empty result then degrades gracefully in the adapter rather
     * than issuing an invalid `query_by`). Weights stay the indexer-owned searchable weights.
     *
     * @param array<string,int> $searchableFields indexer-owned `field ⇒ weight`
     * @param array<string,true> $matchFields fields collected from the request's match queries
     * @return array<string,int>
     */
    private function resolveQueryByFields(array $searchableFields, array $matchFields): array
    {
        if ($matchFields === [] || isset($matchFields['*'])) {
            return $searchableFields;
        }

        $scoped = [];
        foreach (array_keys($matchFields) as $field) {
            $resolved = $this->fieldNameResolver->resolve($field);
            foreach ([$resolved, $resolved . '_value'] as $candidate) {
                if (isset($searchableFields[$candidate])) {
                    $scoped[$candidate] = $searchableFields[$candidate];
                    break;
                }
            }
        }

        return $scoped;
    }

    /**
     * Combine a bool query's must/should/mustNot children into a filter_by fragment.
     *
     * @param QueryBool $query
     * @param array<string,true> $textTerms
     * @param array<string,true> $matchFields
     * @param CollectionSchema|null $schema
     * @throws UnsupportedSearchRequestException
     */
    private function processBoolQuery(
        QueryBool $query,
        array &$textTerms,
        array &$matchFields,
        ?CollectionSchema $schema
    ): ?string {
        $mustParts = [];
        foreach ($query->getMust() as $sub) {
            $part = $this->processQuery($sub, $textTerms, $matchFields, false, $schema);
            if ($part !== null && $part !== '') {
                $mustParts[] = $part;
            }
        }
        foreach ($query->getMustNot() as $sub) {
            $part = $this->processQuery($sub, $textTerms, $matchFields, true, $schema);
            if ($part !== null && $part !== '') {
                $mustParts[] = $part;
            }
        }

        $shouldParts = [];
        foreach ($query->getShould() as $sub) {
            $part = $this->processQuery($sub, $textTerms, $matchFields, false, $schema);
            if ($part !== null && $part !== '') {
                $shouldParts[] = $part;
            }
        }

        return $this->combine($mustParts, $shouldParts);
    }

    /**
     * Resolve a filtered query — either a nested query or a leaf filter.
     *
     * @param FilterQuery $query
     * @param array<string,true> $textTerms
     * @param array<string,true> $matchFields
     * @param bool $negate
     * @param CollectionSchema|null $schema
     * @throws UnsupportedSearchRequestException
     */
    private function processFilterQuery(
        FilterQuery $query,
        array &$textTerms,
        array &$matchFields,
        bool $negate,
        ?CollectionSchema $schema
    ): ?string {
        return match ($query->getReferenceType()) {
            FilterQuery::REFERENCE_QUERY => $this->processQuery(
                $query->getReference(),
                $textTerms,
                $matchFields,
                $negate,
                $schema
            ),
            FilterQuery::REFERENCE_FILTER => $this->processFilterNode($query->getReference(), $negate, $schema),
            default => throw new UnsupportedSearchRequestException(
                sprintf('Unsupported filter reference type "%s".', $query->getReferenceType())
            ),
        };
    }

    /**
     * Convert a request filter into Typesense filter_by syntax.
     *
     * @param FilterInterface $filter
     * @param bool $negate
     * @param CollectionSchema|null $schema
     * @throws UnsupportedSearchRequestException
     */
    private function processFilterNode(FilterInterface $filter, bool $negate, ?CollectionSchema $schema): ?string
    {
        switch ($filter->getType()) {
            case FilterInterface::TYPE_TERM:
                /** @var TermFilter $filter */
                return $this->buildTerm($filter, $negate, $schema);
            case FilterInterface::TYPE_RANGE:
                if ($negate) {
                    throw new UnsupportedSearchRequestException(
                        'Typesense filter_by cannot negate a range filter.'
                    );
                }
                /** @var RangeFilter $filter */
                return $this->buildRange($filter, $schema);
            case FilterInterface::TYPE_BOOL:
                if ($negate) {
                    throw new UnsupportedSearchRequestException(
                        'Typesense filter_by cannot negate a compound boolean filter.'
                    );
                }
                /** @var FilterBool $filter */
                return $this->buildBoolFilter($filter, $schema);
            case FilterInterface::TYPE_WILDCARD:
                if ($negate) {
                    throw new UnsupportedSearchRequestException(
                        'Typesense filter_by cannot negate a wildcard filter.'
                    );
                }
                /** @var WildcardFilter $filter */
                return $this->buildWildcard($filter, $schema);
            default:
                throw new UnsupportedSearchRequestException(
                    sprintf('Unsupported filter type "%s".', $filter->getType())
                );
        }
    }

    /**
     * Term filter: `field:=value`, `field:!=value`, or the array form for multi-value.
     *
     * @param TermFilter $filter
     * @param bool $negate
     * @param CollectionSchema|null $schema
     * @throws UnsupportedSearchRequestException
     */
    private function buildTerm(TermFilter $filter, bool $negate, ?CollectionSchema $schema): string
    {
        $operator = $negate ? ':!=' : ':=';
        $field = $this->filterField($filter->getField(), $schema);

        return $field . $operator . $this->valueFormatter->format($filter->getValue(), $schema?->getType($field));
    }

    /**
     * Wildcard filter as a token prefix match: `` field:`value`* ``.
     *
     * ES/OpenSearch match `*value*`; Typesense matches a token prefix.
     *
     * @param WildcardFilter $filter
     * @param CollectionSchema|null $schema
     * @throws UnsupportedSearchRequestException
     */
    private function buildWildcard(WildcardFilter $filter, ?CollectionSchema $schema): string
    {
        $field = $this->filterField($filter->getField(), $schema);
        $type = $schema?->getType($field);
        if ($schema !== null && $type !== 'string' && $type !== 'string[]') {
            throw new UnsupportedSearchRequestException(
                sprintf('Wildcard filter on "%s" needs a string field, got "%s".', $field, (string)$type)
            );
        }

        $value = trim(str_replace('`', '', (string)$filter->getValue()), '*%');
        if ($value === '') {
            throw new UnsupportedSearchRequestException(
                sprintf('Wildcard filter on "%s" has no value.', $field)
            );
        }

        return $field . ':`' . $value . '`*';
    }

    /**
     * Range filter: inclusive bounds mirroring Magento's ES builder (`gte`/`lte`).
     *
     * @param RangeFilter $filter
     * @param CollectionSchema|null $schema
     * @throws UnsupportedSearchRequestException
     */
    private function buildRange(RangeFilter $filter, ?CollectionSchema $schema): string
    {
        $field = $this->filterField($filter->getField(), $schema);
        $type = $schema?->getType($field);
        $parts = [];

        $from = $filter->getFrom();
        if ($from !== null && $from !== '') {
            $parts[] = $field . ':>=' . $this->valueFormatter->format($from, $type);
        }

        $to = $filter->getTo();
        if ($to !== null && $to !== '') {
            $parts[] = $field . ':<=' . $this->valueFormatter->format($to, $type);
        }

        if ($parts === []) {
            throw new UnsupportedSearchRequestException(
                sprintf('Range filter on "%s" has no bounds.', $field)
            );
        }

        return count($parts) > 1 ? '(' . implode(' && ', $parts) . ')' : $parts[0];
    }

    /**
     * Bool filter: must/mustNot ANDed, should ORed, mirroring {@see combine}.
     *
     * @param FilterBool $filter
     * @param CollectionSchema|null $schema
     * @throws UnsupportedSearchRequestException
     */
    private function buildBoolFilter(FilterBool $filter, ?CollectionSchema $schema): ?string
    {
        $mustParts = [];
        foreach ($filter->getMust() as $sub) {
            $part = $this->processFilterNode($sub, false, $schema);
            if ($part !== null && $part !== '') {
                $mustParts[] = $part;
            }
        }
        foreach ($filter->getMustNot() as $sub) {
            $part = $this->processFilterNode($sub, true, $schema);
            if ($part !== null && $part !== '') {
                $mustParts[] = $part;
            }
        }

        $shouldParts = [];
        foreach ($filter->getShould() as $sub) {
            $part = $this->processFilterNode($sub, false, $schema);
            if ($part !== null && $part !== '') {
                $shouldParts[] = $part;
            }
        }

        return $this->combine($mustParts, $shouldParts);
    }

    /**
     * AND the must parts, add the should parts as a single OR group.
     *
     * @param string[] $mustParts
     * @param string[] $shouldParts
     */
    private function combine(array $mustParts, array $shouldParts): ?string
    {
        $parts = $mustParts;
        if ($shouldParts !== []) {
            $parts[] = count($shouldParts) > 1
                ? '(' . implode(' || ', $shouldParts) . ')'
                : $shouldParts[0];
        }

        if ($parts === []) {
            return null;
        }

        return implode(' && ', $parts);
    }

    /**
     * Resolve a filter's document field; a field the (known) schema lacks can match no document.
     *
     * @param string $attributeCode
     * @param CollectionSchema|null $schema
     * @throws UnsupportedSearchRequestException
     */
    private function filterField(string $attributeCode, ?CollectionSchema $schema): string
    {
        $field = $this->assertFieldName($this->fieldNameResolver->resolve($attributeCode));
        if ($schema !== null && !$schema->has($field)) {
            throw new UnsupportedSearchRequestException(
                sprintf('Filter field "%s" is not declared in the Typesense schema.', $field)
            );
        }

        return $field;
    }

    /**
     * Guard a resolved document field name before it is interpolated into a filter_by clause.
     *
     * Field names come from Magento attribute codes (already `[a-z0-9_]`), so this is a defensive
     * assertion, not a behaviour change for valid input — a name that does not match cannot be a real
     * schema field and would otherwise break the filter grammar, so it is rejected outright.
     *
     * @param string $field
     * @throws UnsupportedSearchRequestException
     */
    private function assertFieldName(string $field): string
    {
        if (preg_match('/^[a-z0-9_]+$/', $field) !== 1) {
            throw new UnsupportedSearchRequestException(
                sprintf('Resolved filter field name "%s" is not a valid Typesense field identifier.', $field)
            );
        }

        return $field;
    }

    /**
     * Build `sort_by` from the request's (BC) sort list; empty when unsortable.
     *
     * `getSort()` is not on {@see RequestInterface} — it exists only on the concrete
     * fulltext request, so it is probed defensively exactly as Magento's own Sort builder does.
     * With a known schema, a select attribute sorts on its `sort_<code>` label field when declared,
     * and a clause on a field the schema does not mark sortable is dropped (Typesense would 404).
     *
     * @param RequestInterface $request
     * @param CollectionSchema|null $schema
     * @param string $alias
     */
    private function buildSort(RequestInterface $request, ?CollectionSchema $schema, string $alias): string
    {
        if (!method_exists($request, 'getSort')) {
            return '';
        }

        $categoryId = $this->categoryIdFromQuery($request->getQuery());

        $sorts = [];
        foreach ($request->getSort() as $item) {
            $field = (string)($item['field'] ?? '');
            if ($field === '') {
                continue;
            }
            if ($field === 'entity_id') {
                // Magento's Fulltext\Collection::_beforeLoad() always appends an `entity_id`
                // tiebreaker sort. ES satisfies it with a script sort over the string `_id`
                // (Sort\Builder\EntityId); Typesense can neither script-sort nor sort on the
                // reserved `id` field, and no `entity_id` field exists in the schema — emitting
                // it verbatim makes Typesense 404 the whole query. Drop it: hits keep the primary
                // sort (or `_text_match`), only the cross-relevance tiebreak is lost.
                continue;
            }
            if ($field === 'relevance') {
                $field = '_text_match';
            } elseif ($schema !== null && $schema->isSort('sort_' . $field)) {
                $field = 'sort_' . $field;
            } else {
                $field = $this->fieldNameResolver->resolve(
                    $field,
                    $categoryId !== null ? ['categoryId' => $categoryId] : []
                );
            }
            if ($schema !== null && $field !== '_text_match' && !$schema->isSort($field)) {
                $this->droppedFieldLogger->log(
                    $alias,
                    $field,
                    sprintf('Dropped Typesense sort on field "%s": not sortable in alias "%s".', $field, $alias)
                );
                continue;
            }
            $direction = strtolower((string)($item['direction'] ?? 'asc'));
            $direction = $direction === 'desc' ? 'desc' : 'asc';
            $sorts[] = $field . ':' . $direction;
        }

        return implode(',', $sorts);
    }

    /**
     * Category id constraining a `position` sort, read from the request's `category` must clause.
     *
     * Mirrors Magento's ES {@see \Magento\Elasticsearch\SearchAdapter\Query\Builder\Sort\Position}:
     * category-page listings carry a `category` term filter whose value is the browsed category, and
     * `position` is stored per category (`position_category_<id>`). Absent it, the resolver falls back
     * to the current/registry category. Only the first id is honoured — Typesense has no multi-field
     * min-position script, and a single-category listing is the case that sorts by position.
     *
     * @param QueryInterface $query
     */
    private function categoryIdFromQuery(QueryInterface $query): ?int
    {
        if ($query->getType() !== QueryInterface::TYPE_BOOL) {
            return null;
        }

        /** @var QueryBool $query */
        $must = $query->getMust();
        if (!isset($must['category']) || !$must['category'] instanceof FilterQuery) {
            return null;
        }

        $reference = $must['category']->getReference();
        if (!$reference instanceof TermFilter) {
            return null;
        }

        $value = $reference->getValue();
        $ids = is_array($value) ? $value : [$value];

        return $ids === [] ? null : (int)reset($ids);
    }
}
