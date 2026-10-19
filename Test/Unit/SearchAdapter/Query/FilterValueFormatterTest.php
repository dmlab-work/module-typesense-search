<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Test\Unit\SearchAdapter\Query;

use DmLab\TypesenseSearch\Exception\UnsupportedSearchRequestException;
use DmLab\TypesenseSearch\SearchAdapter\Query\FilterValueFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FilterValueFormatterTest extends TestCase
{
    /**
     * @return array<string,array{0:mixed,1:string|null,2:string}>
     */
    public static function coercibleProvider(): array
    {
        return [
            'bool true' => [true, 'bool', 'true'],
            'bool 1' => [1, 'bool', 'true'],
            'bool "1"' => ['1', 'bool', 'true'],
            'bool "true"' => ['true', 'bool', 'true'],
            'bool false' => [false, 'bool', 'false'],
            'bool 0' => [0, 'bool', 'false'],
            'bool "0"' => ['0', 'bool', 'false'],
            'bool "false"' => ['false', 'bool', 'false'],
            'bool empty' => ['', 'bool', 'false'],
            'bool[] list' => [['1', '0'], 'bool[]', '[true,false]'],
            'int32 int' => [49, 'int32', '49'],
            'int32 "49"' => ['49', 'int32', '49'],
            'int32 padded' => [' 007 ', 'int32', '7'],
            'int32 zero' => ['000', 'int32', '0'],
            'int64 negative' => ['-12', 'int64', '-12'],
            'int32 true' => [true, 'int32', '1'],
            'int32 false' => [false, 'int32', '0'],
            'int32[] list' => [['49', 50], 'int32[]', '[49,50]'],
            'int64[] list' => [['2', '21'], 'int64[]', '[2,21]'],
            'float int' => [45, 'float', '45'],
            'float string' => ['80.50', 'float', '80.5'],
            'float tiny' => [1.0E-5, 'float', '0.00001'],
            'float large' => [1.0E+15, 'float', '1000000000000000'],
            'float[] list' => [['1.5', 2], 'float[]', '[1.5,2]'],
            'string plain' => ['Nike', 'string', '`Nike`'],
            'string number' => ['12', 'string', '12'],
            'string backtick' => ['a`b', 'string', '`ab`'],
            'string space' => ['Red Wine', 'string', '`Red Wine`'],
            'string comma' => ['a,b', 'string[]', '`a,b`'],
            'unknown type' => ['007', 'geopoint', '`007`'],
            'null schema' => [true, null, 'true'],
            'null schema number' => ['5', null, '5'],
        ];
    }

    #[DataProvider('coercibleProvider')]
    public function testFormat(mixed $value, ?string $type, string $expected): void
    {
        self::assertSame($expected, (new FilterValueFormatter())->format($value, $type));
    }

    /**
     * @return array<string,array{0:mixed,1:string}>
     */
    public static function uncoercibleProvider(): array
    {
        return [
            'bool "yes"' => ['yes', 'bool'],
            'bool 2' => [2, 'bool'],
            'int32 "abc"' => ['abc', 'int32'],
            'int32 decimal' => ['1.5', 'int32'],
            'int32 float' => [1.5, 'int32'],
            'int64 empty' => ['', 'int64'],
            'int32[] mixed' => [['49', 'x'], 'int32[]'],
            'float "abc"' => ['abc', 'float'],
            'float true' => [true, 'float'],
            'nested array' => [[['49']], 'int32[]'],
        ];
    }

    #[DataProvider('uncoercibleProvider')]
    public function testUncoercibleValueThrows(mixed $value, string $type): void
    {
        $this->expectException(UnsupportedSearchRequestException::class);
        (new FilterValueFormatter())->format($value, $type);
    }

    public function testUntypedValueKeepsCanonicalNumbersBare(): void
    {
        $formatter = new FilterValueFormatter();

        self::assertSame('12.5', $formatter->format('12.5', null));
        self::assertSame('`1e3`', $formatter->format('1e3', null));
        self::assertSame('false', $formatter->format(false, null));
    }
}
