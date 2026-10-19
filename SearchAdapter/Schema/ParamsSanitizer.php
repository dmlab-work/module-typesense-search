<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\SearchAdapter\Schema;

/**
 * Drops `query_by` fields the alias schema lacks and `sort_by`/`facet_by` clauses on fields it does
 * not declare sortable/facetable.
 *
 * Typesense 404s the whole search on such a field; dropping it keeps the listing rendering (e.g. a
 * 0.2 indexer's `<code>_value` field before the collection is reindexed).
 * Clauses are split on top-level commas, so `_eval(...)` and `field(...)` arguments stay intact.
 */
class ParamsSanitizer
{
    /**
     * @param DroppedFieldLogger $droppedFieldLogger
     */
    public function __construct(
        private readonly DroppedFieldLogger $droppedFieldLogger
    ) {
    }

    /**
     * Drop query/sort/facet fields the schema cannot serve; a null schema leaves the params unchanged.
     *
     * @param array<string,mixed> $params final Typesense search params
     * @param CollectionSchema|null $schema
     * @return array<string,mixed>
     */
    public function sanitize(array $params, ?CollectionSchema $schema): array
    {
        if ($schema === null) {
            return $params;
        }

        $params = $this->filterQueryBy($params, $schema);

        // `_`-prefixed sort fields are Typesense specials (`_text_match`, `_eval`, `_vector_distance`).
        $params = $this->filter(
            $params,
            'sort_by',
            static fn (string $field): bool => str_starts_with($field, '_') || $schema->isSort($field),
            $schema->getAlias()
        );

        return $this->filter(
            $params,
            'facet_by',
            static fn (string $field): bool => $schema->isFacet($field),
            $schema->getAlias()
        );
    }

    /**
     * Per-`query_by`-field options a modifier may set; one value applies to every field.
     */
    private const PER_FIELD_OPTIONS = ['prefix', 'num_typos', 'infix'];

    /**
     * Keep only declared `query_by` fields, with their `query_by_weights` and per-field option entries.
     *
     * @param array<string,mixed> $params
     * @param CollectionSchema $schema
     * @return array<string,mixed>
     */
    private function filterQueryBy(array $params, CollectionSchema $schema): array
    {
        if (!isset($params['query_by']) || !is_string($params['query_by']) || $params['query_by'] === '') {
            return $params;
        }

        $fields = array_map('trim', explode(',', $params['query_by']));
        $kept = [];
        foreach ($fields as $i => $field) {
            if (!$schema->has($field)) {
                $this->droppedFieldLogger->log(
                    $schema->getAlias(),
                    $field,
                    sprintf(
                        'Dropped Typesense query_by field "%s": not declared in alias "%s".',
                        $field,
                        $schema->getAlias()
                    )
                );
                continue;
            }
            $kept[$i] = $field;
        }

        $params['query_by'] = implode(',', $kept);

        $weights = $this->listParam($params, 'query_by_weights');
        if ($weights !== null && count($weights) === count($fields)) {
            $params['query_by_weights'] = implode(',', array_intersect_key($weights, $kept));
        } else {
            unset($params['query_by_weights']);
        }

        foreach (self::PER_FIELD_OPTIONS as $key) {
            $values = $this->listParam($params, $key);
            if ($values !== null && count($values) > 1 && count($values) === count($fields)) {
                $params[$key] = implode(',', array_intersect_key($values, $kept));
            }
        }

        return $params;
    }

    /**
     * A non-empty comma-list string param as trimmed values; null when absent.
     *
     * @param array<string,mixed> $params
     * @param string $key
     * @return string[]|null
     */
    private function listParam(array $params, string $key): ?array
    {
        if (!isset($params[$key]) || !is_string($params[$key]) || $params[$key] === '') {
            return null;
        }

        return array_map('trim', explode(',', $params[$key]));
    }

    /**
     * Whether a `sort_by`/`facet_by` param has a clause on the field.
     *
     * @param array<string,mixed> $params
     * @param string $key `sort_by` or `facet_by`
     * @param string $field
     */
    public function hasField(array $params, string $key, string $field): bool
    {
        foreach ($this->clauses($params[$key] ?? '') as $clause) {
            if ($this->clauseField($clause) === $field) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove every clause on the field from a `sort_by`/`facet_by` param; an emptied param is unset.
     *
     * @param array<string,mixed> $params
     * @param string $key `sort_by` or `facet_by`
     * @param string $field
     * @return array<string,mixed>
     */
    public function removeField(array $params, string $key, string $field): array
    {
        return $this->filter($params, $key, static fn (string $clauseField): bool => $clauseField !== $field);
    }

    /**
     * Keep only the clauses whose field passes the check.
     *
     * @param array<string,mixed> $params
     * @param string $key
     * @param callable(string):bool $keep
     * @param string|null $alias logs each dropped clause against this alias; null logs nothing
     * @return array<string,mixed>
     */
    private function filter(array $params, string $key, callable $keep, ?string $alias = null): array
    {
        if (!isset($params[$key]) || !is_string($params[$key]) || $params[$key] === '') {
            return $params;
        }

        $kept = [];
        foreach ($this->clauses($params[$key]) as $clause) {
            $field = $this->clauseField($clause);
            if ($keep($field)) {
                $kept[] = $clause;
            } elseif ($alias !== null) {
                $this->droppedFieldLogger->log(
                    $alias,
                    $field,
                    sprintf(
                        'Dropped Typesense %s clause on field "%s": not declared in alias "%s".',
                        $key,
                        $field,
                        $alias
                    )
                );
            }
        }

        if ($kept === []) {
            unset($params[$key]);
        } else {
            $params[$key] = implode(',', $kept);
        }

        return $params;
    }

    /**
     * Split a comma list on top-level commas only.
     *
     * @param mixed $value
     * @return string[]
     */
    private function clauses(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        $clauses = [];
        $depth = 0;
        $current = '';
        foreach (str_split($value) as $char) {
            if ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth = max(0, $depth - 1);
            } elseif ($char === ',' && $depth === 0) {
                $clauses[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $clauses[] = trim($current);

        return array_values(array_filter($clauses, static fn (string $clause): bool => $clause !== ''));
    }

    /**
     * Field name of a clause: everything before the first `:` or `(`.
     *
     * @param string $clause
     */
    private function clauseField(string $clause): string
    {
        return trim(substr($clause, 0, strcspn($clause, ':(')));
    }
}
