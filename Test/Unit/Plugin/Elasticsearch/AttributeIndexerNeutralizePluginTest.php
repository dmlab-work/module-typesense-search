<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Test\Unit\Plugin\Elasticsearch;

use DmLab\TypesenseIndexer\Api\EngineCode;
use DmLab\TypesenseSearch\Plugin\Elasticsearch\AttributeIndexerNeutralizePlugin;
use Magento\Catalog\Model\ResourceModel\Attribute as AttributeResourceModel;
use Magento\Elasticsearch\Model\Indexer\Fulltext\Plugin\Category\Product\Attribute as NativeEsAttributePlugin;
use Magento\Framework\Search\EngineResolverInterface;
use PHPUnit\Framework\TestCase;

class AttributeIndexerNeutralizePluginTest extends TestCase
{
    /** @var NativeEsAttributePlugin */
    private NativeEsAttributePlugin $nativePlugin;

    /** @var AttributeResourceModel */
    private AttributeResourceModel $attributeResource;

    /** @var AttributeResourceModel */
    private AttributeResourceModel $result;

    protected function setUp(): void
    {
        $this->nativePlugin = $this->createStub(NativeEsAttributePlugin::class);
        $this->attributeResource = $this->createStub(AttributeResourceModel::class);
        $this->result = $this->createStub(AttributeResourceModel::class);
    }

    private function plugin(string $engine): AttributeIndexerNeutralizePlugin
    {
        $engineResolver = $this->createStub(EngineResolverInterface::class);
        $engineResolver->method('getCurrentSearchEngine')->willReturn($engine);

        return new AttributeIndexerNeutralizePlugin($engineResolver);
    }

    /**
     * The native afterSave, asserted never to run when we take over — it is the throwing body.
     */
    private function failingProceed(): \Closure
    {
        return function (): AttributeResourceModel {
            self::fail('The native ES afterSave must not run when Typesense is the active engine.');
        };
    }

    public function testEngineTypesenseReturnsResultWithoutRunningNativeBody(): void
    {
        $plugin = $this->plugin(EngineCode::ENGINE);

        $returned = $plugin->aroundAfterSave(
            $this->nativePlugin,
            $this->failingProceed(),
            $this->attributeResource,
            $this->result
        );

        self::assertSame($this->result, $returned);
    }

    public function testEngineOpenSearchRunsNativePluginUnchanged(): void
    {
        $plugin = $this->plugin('opensearch');

        $proceedResult = $this->createStub(AttributeResourceModel::class);
        $called = false;
        $proceed = function ($resource, $result) use (&$called, $proceedResult) {
            $called = true;
            self::assertSame($this->attributeResource, $resource);
            self::assertSame($this->result, $result);

            return $proceedResult;
        };

        $returned = $plugin->aroundAfterSave(
            $this->nativePlugin,
            $proceed,
            $this->attributeResource,
            $this->result
        );

        self::assertTrue($called, 'The native ES plugin must run when the engine is not Typesense.');
        self::assertSame($proceedResult, $returned);
    }
}
