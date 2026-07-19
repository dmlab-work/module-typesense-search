<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseSearch\Test\Unit;

use Magento\Framework\Component\ComponentRegistrar;
use PHPUnit\Framework\TestCase;

class RegistrationTest extends TestCase
{
    public function testModuleIsRegistered(): void
    {
        $paths = (new ComponentRegistrar())->getPaths(ComponentRegistrar::MODULE);

        self::assertArrayHasKey('MageDevGroup_TypesenseSearch', $paths);
    }

    public function testRegisteredPathPointsAtThisModule(): void
    {
        $paths = (new ComponentRegistrar())->getPaths(ComponentRegistrar::MODULE);
        $path = $paths['MageDevGroup_TypesenseSearch'] ?? null;

        self::assertNotNull($path);
        self::assertDirectoryExists($path);
        self::assertFileExists($path . '/etc/module.xml');
    }

    public function testModuleXmlDeclaresSequence(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 2) . '/etc/module.xml');

        self::assertNotFalse($xml);
        self::assertSame('MageDevGroup_TypesenseSearch', (string)$xml->module['name']);
        self::assertSame('0.0.1', (string)$xml->module['setup_version']);

        $sequence = [];
        foreach ($xml->module->sequence->module as $module) {
            $sequence[] = (string)$module['name'];
        }

        // Core (client, exceptions, config) and the indexer (searchable fields, alias
        // resolver, engine code) provide the contracts this module consumes directly,
        // so both must load first — core ahead of the indexer that builds on it.
        self::assertSame('MageDevGroup_TypesenseCore', $sequence[0]);
        self::assertContains('MageDevGroup_TypesenseIndexer', $sequence);
        self::assertContains('Magento_CatalogSearch', $sequence);
        self::assertContains('Magento_AdvancedSearch', $sequence);
        // ES is a structural dependency: Config::isElasticsearchEnabled gates the
        // indexer path and we must appear in its engineList (see plan Context).
        self::assertContains('Magento_Elasticsearch', $sequence);
        self::assertContains('Magento_Config', $sequence);
    }

    public function testComposerRequiresIndexerAndElasticsearch(): void
    {
        $composer = json_decode(
            (string)file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
            true
        );

        self::assertSame('magedevgroup/module-typesense-search', $composer['name']);
        self::assertSame('OSL-3.0', $composer['license']);
        self::assertSame('0.0.1', $composer['version']);
        self::assertSame('magento2-module', $composer['type']);

        self::assertArrayHasKey('magedevgroup/module-typesense-core', $composer['require']);
        self::assertArrayHasKey('magedevgroup/module-typesense-indexer', $composer['require']);
        self::assertArrayHasKey('magento/module-search', $composer['require']);
        self::assertArrayHasKey('magento/module-advanced-search', $composer['require']);
        self::assertArrayHasKey('magento/module-catalog-search', $composer['require']);
        // Structural dependency — see Context in the plan.
        self::assertArrayHasKey('magento/module-elasticsearch', $composer['require']);

        self::assertArrayHasKey(
            'MageDevGroup\\TypesenseSearch\\',
            $composer['autoload']['psr-4']
        );
    }
}
