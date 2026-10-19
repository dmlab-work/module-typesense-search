<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Block\Adminhtml\System\Config;

/**
 * Typesense Test Connection button: posts the unsaved connection fields to the core controller.
 */
class TestConnection extends \Magento\AdvancedSearch\Block\Adminhtml\System\Config\TestConnection
{
    /**
     * @inheritdoc
     */
    protected function _getFieldMapping(): array
    {
        $fields = [
            'hostname' => 'catalog_search_typesense_server_hostname',
            'port' => 'catalog_search_typesense_server_port',
            'protocol' => 'catalog_search_typesense_protocol',
            'apiKey' => 'catalog_search_typesense_api_key',
            'timeout' => 'catalog_search_typesense_server_timeout',
        ];

        return array_merge(parent::_getFieldMapping(), $fields);
    }
}
