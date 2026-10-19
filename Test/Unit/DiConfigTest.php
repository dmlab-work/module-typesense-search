<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Asserts the engine is registered into Magento's search-subsystem pools under the
 * `typesense` key, pointing at this module's classes.
 */
class DiConfigTest extends TestCase
{
    /** @var \SimpleXMLElement */
    private \SimpleXMLElement $di;

    protected function setUp(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 2) . '/etc/di.xml');
        self::assertNotFalse($xml);
        $this->di = $xml;
    }

    /**
     * @return string[]
     */
    private function poolItems(string $typeName, string $argument): array
    {
        $items = [];
        foreach ($this->di->type as $type) {
            if ((string)$type['name'] !== $typeName) {
                continue;
            }
            foreach ($type->arguments->argument as $arg) {
                if ((string)$arg['name'] !== $argument) {
                    continue;
                }
                foreach ($arg->item as $item) {
                    $items[(string)$item['name']] = trim((string)$item);
                }
            }
        }

        return $items;
    }

    public function testClientFactoryRegistered(): void
    {
        $items = $this->poolItems('Magento\AdvancedSearch\Model\Client\ClientResolver', 'clientFactories');

        self::assertSame(
            'DmLab\TypesenseSearch\Model\Client\ClientFactory',
            $items['typesense'] ?? null
        );
    }

    public function testClientOptionsRegistered(): void
    {
        $items = $this->poolItems('Magento\AdvancedSearch\Model\Client\ClientResolver', 'clientOptions');

        self::assertSame(
            'DmLab\TypesenseSearch\Model\Client\ClientOptions',
            $items['typesense'] ?? null
        );
    }

    public function testValidatorRegistered(): void
    {
        $items = $this->poolItems('Magento\Search\Model\SearchEngine\Validator', 'engineValidators');

        self::assertSame('DmLab\TypesenseSearch\Setup\Validator', $items['typesense'] ?? null);
    }

    public function testNoInstallConfigRegistered(): void
    {
        foreach ($this->di->type as $type) {
            self::assertNotSame('Magento\Search\Setup\CompositeInstallConfig', (string)$type['name']);
        }
    }

    public function testTestConnectionButtonDeclared(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 2) . '/etc/adminhtml/system.xml');
        self::assertNotFalse($xml);

        $fields = $xml->xpath(
            "/config/system/section[@id='catalog']/group[@id='search']/field[@id='typesense_test_connect_wizard']"
        );
        self::assertCount(1, $fields);
        self::assertSame(
            'DmLab\TypesenseSearch\Block\Adminhtml\System\Config\TestConnection',
            (string)$fields[0]->frontend_model
        );
        self::assertSame('Test Connection', (string)$fields[0]->button_label);
        self::assertSame('typesense', (string)$fields[0]->depends->field);
    }

    public function testEngineRegisteredInList(): void
    {
        $items = $this->poolItems('Magento\Elasticsearch\Model\Config', 'engineList');

        self::assertSame('typesense', $items['typesense'] ?? null);
    }

    public function testEngineResolverAcceptsTypesense(): void
    {
        $items = $this->poolItems('Magento\Search\Model\EngineResolver', 'engines');

        self::assertSame('typesense', $items['typesense'] ?? null);
    }

    public function testAdminSourceEngineLabel(): void
    {
        $items = $this->poolItems('Magento\Search\Model\Adminhtml\System\Config\Source\Engine', 'engines');

        self::assertSame('Typesense', $items['typesense'] ?? null);
    }

    public function testPageSizeRegistered(): void
    {
        $items = $this->poolItems('Magento\Search\Model\Search\PageSizeProvider', 'pageSizeBySearchEngine');

        self::assertSame('10000', $items['typesense'] ?? null);
    }

    public function testEngineNameResolvesToOurAdapter(): void
    {
        $items = $this->poolItems('Magento\Search\Model\AdapterFactory', 'adapters');

        self::assertSame(
            'DmLab\TypesenseSearch\SearchAdapter\Adapter',
            $items['typesense'] ?? null
        );
    }

    public function testReusedGenericEsClasses(): void
    {
        $engineProvider = $this->poolItems('Magento\CatalogSearch\Model\ResourceModel\EngineProvider', 'engines');
        self::assertSame(
            'Magento\Elasticsearch\Model\ResourceModel\Engine',
            $engineProvider['typesense'] ?? null
        );

        $strategies = $this->poolItems(
            'Magento\CatalogSearch\Model\Advanced\ProductCollectionPrepareStrategyProvider',
            'strategies'
        );
        self::assertSame(
            'Magento\Elasticsearch\Model\Advanced\ProductCollectionPrepareStrategy',
            $strategies['typesense'] ?? null
        );
    }

    public function testIndexerImplementationsRegistered(): void
    {
        $handlers = $this->poolItems('Magento\CatalogSearch\Model\Indexer\IndexerHandlerFactory', 'handlers');
        self::assertSame(
            'DmLab\TypesenseIndexer\Model\Indexer\IndexerHandler',
            $handlers['typesense'] ?? null
        );

        $structures = $this->poolItems('Magento\CatalogSearch\Model\Indexer\IndexStructureFactory', 'structures');
        self::assertSame(
            'DmLab\TypesenseIndexer\Model\Indexer\IndexStructure',
            $structures['typesense'] ?? null
        );
    }

    /**
     * @return array<string, string>
     */
    private function virtualTypeFactories(string $virtualTypeName): array
    {
        $items = [];
        foreach ($this->di->virtualType as $vt) {
            if ((string)$vt['name'] !== $virtualTypeName) {
                continue;
            }
            foreach ($vt->arguments->argument as $arg) {
                if ((string)$arg['name'] !== 'factories') {
                    continue;
                }
                foreach ($arg->item as $item) {
                    $items[(string)$item['name']] = trim((string)$item);
                }
            }
        }

        return $items;
    }

    public function testLayerCollectionFactoriesRegistered(): void
    {
        $category = $this->virtualTypeFactories('elasticsearchLayerCategoryItemCollectionProvider');
        self::assertSame('elasticsearchCategoryCollectionFactory', $category['typesense'] ?? null);

        $search = $this->virtualTypeFactories('elasticsearchLayerSearchItemCollectionProvider');
        self::assertSame('elasticsearchFulltextSearchCollectionFactory', $search['typesense'] ?? null);

        $advanced = $this->poolItems('Magento\CatalogSearch\Model\Search\ItemCollectionProvider', 'factories');
        self::assertSame('elasticsearchAdvancedCollectionFactory', $advanced['typesense'] ?? null);
    }

    public function testDynamicDataProviderRegistered(): void
    {
        $items = $this->poolItems('Magento\Framework\Search\Dynamic\DataProviderFactory', 'dataProviders');

        self::assertSame(
            'DmLab\TypesenseSearch\SearchAdapter\Dynamic\DataProvider',
            $items['typesense'] ?? null
        );
    }

    public function testIntervalRegistered(): void
    {
        $items = $this->poolItems('Magento\Framework\Search\Dynamic\IntervalFactory', 'intervals');

        self::assertSame(
            'DmLab\TypesenseSearch\SearchAdapter\Aggregation\Interval',
            $items['typesense'] ?? null
        );
    }

    public function testSuggestionsRegistered(): void
    {
        $items = $this->poolItems('Magento\AdvancedSearch\Model\SuggestedQueries', 'data');

        self::assertSame(
            'DmLab\TypesenseSearch\Model\DataProvider\Suggestions',
            $items['typesense'] ?? null
        );
    }

    public function testResponseProcessorPoolRegisteredEmpty(): void
    {
        $found = false;
        foreach ($this->di->type as $type) {
            if ((string)$type['name'] !== 'DmLab\TypesenseSearch\SearchAdapter\Response\ResponseProcessor') {
                continue;
            }
            foreach ($type->arguments->argument as $arg) {
                if ((string)$arg['name'] === 'processors') {
                    $found = true;
                    self::assertSame('array', (string)$arg->attributes('xsi', true)['type']);
                    self::assertCount(0, $arg->item);
                }
            }
        }

        self::assertTrue($found);
    }

    public function testEsAttributePluginNeutralized(): void
    {
        $target = 'Magento\Elasticsearch\Model\Indexer\Fulltext\Plugin\Category\Product\Attribute';
        $plugin = null;
        foreach ($this->di->type as $type) {
            if ((string)$type['name'] !== $target) {
                continue;
            }
            foreach ($type->plugin as $candidate) {
                if ((string)$candidate['name'] === 'dmlab_typesense_search_neutralise_es_attribute') {
                    $plugin = $candidate;
                }
            }
        }

        self::assertNotNull($plugin);
        self::assertSame(
            'DmLab\TypesenseSearch\Plugin\Elasticsearch\AttributeIndexerNeutralizePlugin',
            (string)$plugin['type']
        );
        self::assertNotSame('true', (string)$plugin['disabled']);
    }

    public function testEsInternalProxiesNotRegistered(): void
    {
        foreach ($this->di->type as $type) {
            self::assertNotSame(
                'Magento\Elasticsearch\Elasticsearch5\Model\Adapter\FieldMapper\ProductFieldMapperProxy',
                (string)$type['name']
            );
            self::assertNotSame(
                'Magento\Elasticsearch\Model\Adapter\Elasticsearch',
                (string)$type['name']
            );
        }
    }

    public function testSearchEngineDeclaresSynonymsSupported(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 2) . '/etc/search_engine.xml');
        self::assertNotFalse($xml);

        $engineName = null;
        $synonyms = null;
        foreach ($xml->engine as $engine) {
            if ((string)$engine['name'] !== 'typesense') {
                continue;
            }
            $engineName = 'typesense';
            foreach ($engine->feature as $feature) {
                if ((string)$feature['name'] === 'synonyms') {
                    $synonyms = (string)$feature['support'];
                }
            }
        }

        self::assertSame('typesense', $engineName);
        self::assertSame('true', $synonyms);
    }
}
