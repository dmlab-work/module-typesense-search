<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Test\Unit\Block\Adminhtml\System\Config;

use DmLab\TypesenseSearch\Block\Adminhtml\System\Config\TestConnection;
use PHPUnit\Framework\TestCase;

class TestConnectionTest extends TestCase
{
    public function testMappingMergedWithParentEngine(): void
    {
        $block = (new \ReflectionClass(TestConnection::class))->newInstanceWithoutConstructor();
        $mapping = (new \ReflectionMethod($block, '_getFieldMapping'))->invoke($block);

        self::assertSame(
            [
                'engine' => 'catalog_search_engine',
                'hostname' => 'catalog_search_typesense_server_hostname',
                'port' => 'catalog_search_typesense_server_port',
                'protocol' => 'catalog_search_typesense_protocol',
                'apiKey' => 'catalog_search_typesense_api_key',
                'timeout' => 'catalog_search_typesense_server_timeout',
            ],
            $mapping
        );
    }
}
