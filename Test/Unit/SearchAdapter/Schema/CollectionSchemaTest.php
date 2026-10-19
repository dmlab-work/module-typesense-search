<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Test\Unit\SearchAdapter\Schema;

use DmLab\TypesenseSearch\SearchAdapter\Schema\CollectionSchema;
use PHPUnit\Framework\TestCase;

class CollectionSchemaTest extends TestCase
{
    private function schema(): CollectionSchema
    {
        return new CollectionSchema([
            ['name' => '.*', 'type' => 'auto'],
            ['name' => 'name', 'type' => 'string', 'facet' => false, 'sort' => true],
            ['name' => 'color', 'type' => 'int32[]', 'facet' => true, 'sort' => false],
            ['name' => 'price_[0-9]+_.*', 'type' => 'float', 'facet' => true, 'sort' => true, 'optional' => true],
            ['name' => 'price_0_1', 'type' => 'int64', 'facet' => false, 'sort' => false],
            ['name' => 'position_category_.*', 'type' => 'int32', 'sort' => true, 'optional' => true],
            ['name' => 'position_.*', 'type' => 'string'],
            ['name' => 'a#b.*', 'type' => 'string', 'facet' => true],
            ['type' => 'string'],
            'garbage',
        ]);
    }

    public function testExactField(): void
    {
        $schema = $this->schema();

        self::assertTrue($schema->has('name'));
        self::assertSame('string', $schema->getType('name'));
        self::assertTrue($schema->isSort('name'));
        self::assertFalse($schema->isFacet('name'));
        self::assertSame('int32[]', $schema->getType('color'));
        self::assertTrue($schema->isFacet('color'));
        self::assertFalse($schema->isSort('color'));
    }

    public function testRegexFieldCoversConcreteNames(): void
    {
        $schema = $this->schema();

        self::assertTrue($schema->has('price_4_2'));
        self::assertSame('float', $schema->getType('price_4_2'));
        self::assertTrue($schema->isFacet('price_4_2'));
        self::assertTrue($schema->isSort('price_4_2'));
        self::assertSame('int32', $schema->getType('position_category_41'));
        self::assertTrue($schema->has('a#b1'));
    }

    public function testExactNameWinsOverRegex(): void
    {
        $schema = $this->schema();

        self::assertSame('int64', $schema->getType('price_0_1'));
        self::assertFalse($schema->isFacet('price_0_1'));
    }

    public function testDeclaresExactlyIgnoresRegexCoverage(): void
    {
        $schema = $this->schema();

        self::assertTrue($schema->declaresExactly('price_0_1'));
        self::assertFalse($schema->declaresExactly('price_4_2'));
        self::assertFalse($schema->declaresExactly('missing'));
    }

    public function testFirstMatchingRegexWins(): void
    {
        self::assertSame('int32', $this->schema()->getType('position_category_7'));
        self::assertSame('string', $this->schema()->getType('position_other'));
    }

    public function testRegexMustMatchTheWholeName(): void
    {
        self::assertFalse($this->schema()->has('xprice_1'));
    }

    public function testIndexerPriceRegexCoversScopedPriceOnly(): void
    {
        $schema = $this->schema();

        self::assertTrue($schema->has('price_4_2'));
        self::assertFalse($schema->has('price_view'));
    }

    public function testAutoCatchAllIsNotADeclaration(): void
    {
        $schema = $this->schema();

        self::assertFalse($schema->has('activity'));
        self::assertNull($schema->getType('activity'));
        self::assertFalse($schema->isFacet('activity'));
        self::assertFalse($schema->isSort('activity'));
    }

    public function testMissingFlagsDefaultToFalse(): void
    {
        $schema = new CollectionSchema([['name' => 'sku', 'type' => 'string']]);

        self::assertTrue($schema->has('sku'));
        self::assertFalse($schema->isFacet('sku'));
        self::assertFalse($schema->isSort('sku'));
    }
}
