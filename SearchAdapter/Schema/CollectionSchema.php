<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\SearchAdapter\Schema;

/**
 * Immutable view of a collection's declared fields, as returned by `GET /collections/{alias}`.
 *
 * Exact field names win; otherwise the first declared regex field that fully matches applies,
 * so `price_[0-9]+_.*` covers `price_4_2`.
 */
class CollectionSchema
{
    /**
     * Characters that make a declared field name a regex declaration.
     */
    private const REGEX_CHARS = '.*+?[](){}|\\^$';

    /**
     * @var array<string,array<string,mixed>>
     */
    private array $exact = [];

    /**
     * @var array<string,array<string,mixed>> compiled pattern ⇒ field definition, in declaration order
     */
    private array $patterns = [];

    /**
     * @param array<int,array<string,mixed>> $fields the `fields` list of the collection body
     * @param string $alias alias the schema was read through
     */
    public function __construct(
        array $fields,
        private readonly string $alias = ''
    ) {
        foreach ($fields as $definition) {
            if (!is_array($definition) || !isset($definition['name']) || !is_string($definition['name'])) {
                continue;
            }
            $name = $definition['name'];
            if (strpbrk($name, self::REGEX_CHARS) === false) {
                $this->exact[$name] = $definition;
                continue;
            }
            // `.*` with `auto` is Typesense's catch-all auto-detection, not a declaration.
            if ($name === '.*' && ($definition['type'] ?? null) === 'auto') {
                continue;
            }
            $this->patterns['#^(?:' . str_replace('#', '\\#', $name) . ')$#'] = $definition;
        }
    }

    /**
     * Alias the schema was read through.
     */
    public function getAlias(): string
    {
        return $this->alias;
    }

    /**
     * Whether the field is declared, exactly or via a regex field.
     *
     * @param string $field
     */
    public function has(string $field): bool
    {
        return $this->definition($field) !== null;
    }

    /**
     * Whether the field is declared by its exact name, not only through a regex field.
     *
     * @param string $field
     */
    public function declaresExactly(string $field): bool
    {
        return isset($this->exact[$field]);
    }

    /**
     * Declared Typesense type (`int32`, `string[]`, …), or null when the field is not declared.
     *
     * @param string $field
     */
    public function getType(string $field): ?string
    {
        $type = $this->definition($field)['type'] ?? null;

        return is_string($type) ? $type : null;
    }

    /**
     * Whether the field is declared with `facet: true`.
     *
     * @param string $field
     */
    public function isFacet(string $field): bool
    {
        return (bool)($this->definition($field)['facet'] ?? false);
    }

    /**
     * Whether the field is declared with `sort: true`.
     *
     * @param string $field
     */
    public function isSort(string $field): bool
    {
        return (bool)($this->definition($field)['sort'] ?? false);
    }

    /**
     * Definition governing a concrete field name.
     *
     * @param string $field
     * @return array<string,mixed>|null
     */
    private function definition(string $field): ?array
    {
        if (isset($this->exact[$field])) {
            return $this->exact[$field];
        }

        foreach ($this->patterns as $pattern => $definition) {
            if (preg_match($pattern, $field) === 1) {
                return $definition;
            }
        }

        return null;
    }
}
