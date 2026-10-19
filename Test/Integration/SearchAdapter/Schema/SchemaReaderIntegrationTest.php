<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Test\Integration\SearchAdapter\Schema;

use DmLab\TypesenseSearch\SearchAdapter\Schema\SchemaReader;
use DmLab\TypesenseSearch\Test\Integration\Support\ArrayCache;
use DmLab\TypesenseSearch\Test\Integration\TypesenseTestCase;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\NullLogger;

class SchemaReaderIntegrationTest extends TypesenseTestCase
{
    /** @var SchemaReader */
    private SchemaReader $reader;

    protected function setUp(): void
    {
        $this->reader = new SchemaReader(self::$client, new ArrayCache(), new Json(), new NullLogger());
    }

    public function testAliasResolvesToTheCollectionSchema(): void
    {
        $schema = $this->reader->get(self::$alias);

        self::assertNotNull($schema);
        self::assertTrue($schema->has('sku'));
        self::assertSame('int32[]', $schema->getType('color'));
        self::assertTrue($schema->isFacet('category_ids'));
    }

    public function testRegexFieldCoversConcreteName(): void
    {
        $schema = $this->reader->get(self::$alias);

        self::assertNotNull($schema);
        self::assertTrue($schema->has('price_0_1'));
        self::assertSame('float', $schema->getType('price_0_1'));
        self::assertTrue($schema->isSort('price_0_1'));
        self::assertTrue($schema->isSort('position_category_999'));
        self::assertFalse($schema->has('price_view'));
    }

    public function testAbsentFieldIsNotDeclared(): void
    {
        $schema = $this->reader->get(self::$alias);

        self::assertNotNull($schema);
        self::assertFalse($schema->has('no_such_field'));
    }

    public function testUnknownAliasGivesNull(): void
    {
        self::assertNull($this->reader->get(self::$alias . '_missing'));
    }
}
