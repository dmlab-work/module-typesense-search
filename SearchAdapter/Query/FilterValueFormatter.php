<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\SearchAdapter\Query;

use DmLab\TypesenseSearch\Exception\UnsupportedSearchRequestException;

/**
 * Formats a `filter_by` value for the declared Typesense type of its field.
 *
 * Typesense rejects `eco_collection:=1` on a `bool` field (HTTP 400), so values are coerced by type;
 * string/unknown types (or no schema) keep the untyped escaping.
 */
class FilterValueFormatter
{
    private const INT_TYPES = ['int32', 'int64'];

    /**
     * Format a scalar or a list (element-wise, inside `[…]`) for a field of the given type.
     *
     * @param mixed $value
     * @param string|null $type declared Typesense type, null when unknown
     * @throws UnsupportedSearchRequestException when the value cannot be coerced to the type
     */
    public function format(mixed $value, ?string $type): string
    {
        if (is_array($value)) {
            $formatted = [];
            foreach ($value as $item) {
                $formatted[] = $this->formatScalar($item, $type);
            }

            return '[' . implode(',', $formatted) . ']';
        }

        return $this->formatScalar($value, $type);
    }

    /**
     * Untyped escaping: canonical numbers and booleans bare, everything else backtick-wrapped.
     *
     * Only a canonical number stays bare; a merely `is_numeric()`-looking string (`"007"`, `"1e3"`,
     * `" 12"`, `"+5"`) targets a string field and must be quoted. A literal backtick cannot appear
     * in the grammar, so it is dropped.
     *
     * @param mixed $value
     */
    private function escapeValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $string = (string)$value;
        if (preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/', $string) === 1) {
            return $string;
        }

        return '`' . str_replace('`', '', $string) . '`';
    }

    /**
     * Format one value for the declared type.
     *
     * @param mixed $value
     * @param string|null $type
     * @throws UnsupportedSearchRequestException
     */
    private function formatScalar(mixed $value, ?string $type): string
    {
        if ($value !== null && !is_scalar($value)) {
            throw new UnsupportedSearchRequestException('Typesense filter values must be scalar.');
        }

        $baseType = $type === null ? null : (str_ends_with($type, '[]') ? substr($type, 0, -2) : $type);

        return match (true) {
            $baseType === 'bool' => $this->formatBool($value),
            in_array($baseType, self::INT_TYPES, true) => $this->formatInt($value, $baseType),
            $baseType === 'float' => $this->formatFloat($value),
            default => $this->escapeValue($value),
        };
    }

    /**
     * Coerce to `true`/`false`.
     *
     * @param bool|int|float|string|null $value
     * @throws UnsupportedSearchRequestException
     */
    private function formatBool(bool|int|float|string|null $value): string
    {
        if (in_array($value, [true, 1, '1', 'true'], true)) {
            return 'true';
        }
        if (in_array($value, [false, 0, '0', 'false', ''], true)) {
            return 'false';
        }

        throw $this->uncoercible($value, 'bool');
    }

    /**
     * Coerce to a canonical integer.
     *
     * @param bool|int|float|string|null $value
     * @param string $type
     * @throws UnsupportedSearchRequestException
     */
    private function formatInt(bool|int|float|string|null $value, string $type): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value)) {
            return (string)$value;
        }
        if (is_string($value) && preg_match('/^(-?)(\d+)$/', trim($value), $matches) === 1) {
            $digits = ltrim($matches[2], '0');

            return $digits === '' ? '0' : $matches[1] . $digits;
        }

        throw $this->uncoercible($value, $type);
    }

    /**
     * Coerce to a canonical decimal, never an exponent.
     *
     * @param bool|int|float|string|null $value
     * @throws UnsupportedSearchRequestException
     */
    private function formatFloat(bool|int|float|string|null $value): string
    {
        if (is_bool($value) || !is_numeric($value)) {
            throw $this->uncoercible($value, 'float');
        }

        return rtrim(rtrim(sprintf('%.6F', (float)$value), '0'), '.');
    }

    /**
     * Exception for a value the type cannot take.
     *
     * @param mixed $value
     * @param string $type
     */
    private function uncoercible(mixed $value, string $type): UnsupportedSearchRequestException
    {
        return new UnsupportedSearchRequestException(
            sprintf('Filter value %s cannot be coerced to Typesense type "%s".', json_encode($value), $type)
        );
    }
}
