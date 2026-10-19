<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Plugin\Elasticsearch;

use DmLab\TypesenseIndexer\Api\EngineCode;
use Magento\Catalog\Model\ResourceModel\Attribute as AttributeResourceModel;
use Magento\Elasticsearch\Model\Indexer\Fulltext\Plugin\Category\Product\Attribute as NativeEsAttributePlugin;
use Magento\Framework\Search\EngineResolverInterface;

/**
 * Short-circuits the native ES attribute plugin under Typesense: its `isElasticsearchEnabled()` gate is
 * true for us (engine list), and it throws when the handler is not an ElasticsearchIndexerHandler.
 */
class AttributeIndexerNeutralizePlugin
{
    /**
     * @param EngineResolverInterface $engineResolver
     */
    public function __construct(
        private readonly EngineResolverInterface $engineResolver
    ) {
    }

    /**
     * Skip the native plugin when Typesense is the engine.
     *
     * @param NativeEsAttributePlugin $subject
     * @param \Closure $proceed
     * @param AttributeResourceModel $attributeResource
     * @param AttributeResourceModel $result
     * @return AttributeResourceModel
     */
    public function aroundAfterSave(
        NativeEsAttributePlugin $subject,
        \Closure $proceed,
        AttributeResourceModel $attributeResource,
        AttributeResourceModel $result
    ): AttributeResourceModel {
        if ($this->engineResolver->getCurrentSearchEngine() === EngineCode::ENGINE) {
            return $result;
        }

        return $proceed($attributeResource, $result);
    }
}
