<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Test\Unit\SearchAdapter\Schema;

use DmLab\TypesenseSearch\SearchAdapter\Schema\CollectionSchema;
use DmLab\TypesenseSearch\SearchAdapter\Schema\DroppedFieldLogger;
use DmLab\TypesenseSearch\SearchAdapter\Schema\ParamsSanitizer;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ParamsSanitizerTest extends TestCase
{
    /** @var DroppedFieldLogger */
    private DroppedFieldLogger $droppedFieldLogger;
    /** @var ParamsSanitizer */
    private ParamsSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->droppedFieldLogger = $this->createMock(DroppedFieldLogger::class);
        $this->sanitizer = new ParamsSanitizer($this->droppedFieldLogger);
    }

    private function schema(): CollectionSchema
    {
        return new CollectionSchema([
            ['name' => 'name', 'type' => 'string', 'sort' => true],
            ['name' => 'color', 'type' => 'int32[]', 'facet' => true],
            ['name' => 'description', 'type' => 'string'],
            ['name' => 'price_[0-9]+_.*', 'type' => 'float', 'facet' => true, 'sort' => true, 'optional' => true],
            ['name' => 'position_category_.*', 'type' => 'int32', 'sort' => true, 'optional' => true],
        ], 'alias_1');
    }

    public function testNullSchemaLeavesParamsUnchanged(): void
    {
        $params = ['q' => '*', 'sort_by' => 'missing:asc', 'facet_by' => 'missing'];
        $this->droppedFieldLogger->expects($this->never())->method('log');

        self::assertSame($params, $this->sanitizer->sanitize($params, null));
    }

    public function testDropsUndeclaredQueryByFieldsWithTheirWeights(): void
    {
        $this->droppedFieldLogger->expects($this->once())->method('log')
            ->with('alias_1', 'color_value', $this->stringContains('alias_1'));

        $params = $this->sanitizer->sanitize(
            ['q' => 'red', 'query_by' => 'name,color_value,description', 'query_by_weights' => '5,1,2'],
            $this->schema()
        );

        self::assertSame('name,description', $params['query_by']);
        self::assertSame('5,2', $params['query_by_weights']);
    }

    public function testDropsPerFieldOptionsWithTheirQueryByFieldAndKeepsSingletons(): void
    {
        $params = $this->sanitizer->sanitize(
            [
                'q' => 'red',
                'query_by' => 'name,color_value,description',
                'prefix' => 'true,false,false',
                'num_typos' => '2,1,0',
                'infix' => 'off',
            ],
            $this->schema()
        );

        self::assertSame('name,description', $params['query_by']);
        self::assertSame('true,false', $params['prefix']);
        self::assertSame('2,0', $params['num_typos']);
        self::assertSame('off', $params['infix']);
    }

    public function testEmptiedQueryByStaysEmptyForTheAdapterToDegrade(): void
    {
        $params = $this->sanitizer->sanitize(
            ['q' => 'red', 'query_by' => 'color_value', 'query_by_weights' => '1'],
            $this->schema()
        );

        self::assertSame('', $params['query_by']);
        self::assertSame('', $params['query_by_weights']);
    }

    public function testMisalignedWeightsAreDropped(): void
    {
        $params = $this->sanitizer->sanitize(
            ['q' => 'red', 'query_by' => 'name,description', 'query_by_weights' => '5'],
            $this->schema()
        );

        self::assertSame('name,description', $params['query_by']);
        self::assertArrayNotHasKey('query_by_weights', $params);
    }

    public function testDropsUnsortableAndMissingSortClauses(): void
    {
        $this->droppedFieldLogger->expects($this->exactly(2))->method('log');

        $params = $this->sanitizer->sanitize(
            ['sort_by' => '_text_match:desc,description:asc,name:asc,missing:desc,price_4_2:asc'],
            $this->schema()
        );

        self::assertSame('_text_match:desc,name:asc,price_4_2:asc', $params['sort_by']);
    }

    public function testKeepsSpecialSortClausesWithArguments(): void
    {
        $sortBy = '_eval([(color:49):2, (color:50):1]):desc,position_category_41:asc';

        $params = $this->sanitizer->sanitize(['sort_by' => $sortBy], $this->schema());

        self::assertSame($sortBy, $params['sort_by']);
    }

    public function testDropsUnfacetedFacetFields(): void
    {
        $this->droppedFieldLogger->expects($this->exactly(2))->method('log');

        $params = $this->sanitizer->sanitize(
            ['facet_by' => 'color,description,missing,price_0_1(cheap:[0, 100])'],
            $this->schema()
        );

        self::assertSame('color,price_0_1(cheap:[0, 100])', $params['facet_by']);
    }

    public function testEmptiedParamIsUnset(): void
    {
        $params = $this->sanitizer->sanitize(
            ['q' => '*', 'sort_by' => 'missing:asc', 'facet_by' => 'description'],
            $this->schema()
        );

        self::assertSame(['q' => '*'], $params);
    }

    public function testHasAndRemoveField(): void
    {
        $params = ['sort_by' => 'name:asc,sort_color:desc', 'facet_by' => 'color'];

        self::assertTrue($this->sanitizer->hasField($params, 'sort_by', 'sort_color'));
        self::assertFalse($this->sanitizer->hasField($params, 'facet_by', 'sort_color'));
        self::assertFalse($this->sanitizer->hasField([], 'sort_by', 'name'));

        $this->droppedFieldLogger->expects($this->never())->method('log');
        self::assertSame(
            ['sort_by' => 'name:asc', 'facet_by' => 'color'],
            $this->sanitizer->removeField($params, 'sort_by', 'sort_color')
        );
        self::assertSame(
            ['sort_by' => 'name:asc,sort_color:desc'],
            $this->sanitizer->removeField($params, 'facet_by', 'color')
        );
    }
}
