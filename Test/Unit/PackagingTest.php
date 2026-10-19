<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Test\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class PackagingTest extends TestCase
{
    private const OSL_LINE = 'Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.';

    private const REQUIRED_PACKAGES = [
        'php',
        'magento/framework',
        'magento/module-search',
        'magento/module-advanced-search',
        'magento/module-catalog-search',
        'magento/module-catalog',
        'magento/module-customer',
        'magento/module-store',
        'magento/module-config',
        'magento/module-elasticsearch',
        'psr/log',
        'dmlab/module-typesense-core',
        'dmlab/module-typesense-indexer',
    ];

    public function testComposerRequiresListedPackagesWithoutOpenRanges(): void
    {
        $require = $this->composer()['require'];

        self::assertEqualsCanonicalizing(self::REQUIRED_PACKAGES, array_keys($require));
        foreach ($require as $package => $constraint) {
            self::assertStringNotContainsString('>=', $constraint, $package);
        }
        self::assertSame('^0.2.0', $require['dmlab/module-typesense-core']);
        self::assertSame('^0.2.0', $require['dmlab/module-typesense-indexer']);
    }

    public function testComposerKeepsVersionKey(): void
    {
        self::assertArrayHasKey('version', $this->composer());
    }

    public function testModuleXmlHasNoSetupVersion(): void
    {
        $xml = simplexml_load_file($this->root() . '/etc/module.xml');

        self::assertNotFalse($xml);
        self::assertFalse(isset($xml->module['setup_version']));
    }

    public function testEveryPhpFileCarriesOslLine(): void
    {
        $files = $this->phpFiles();

        self::assertNotEmpty($files);
        foreach ($files as $file) {
            self::assertStringContainsString(self::OSL_LINE, (string)file_get_contents($file), $file);
        }
    }

    public function testI18nCsvHasTwoColumns(): void
    {
        $handle = fopen($this->root() . '/i18n/en_US.csv', 'rb');
        self::assertNotFalse($handle);

        $phrases = [];
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            self::assertCount(2, $row);
            $phrases[] = $row[0];
        }
        fclose($handle);

        self::assertContains('Test Connection', $phrases);
        self::assertContains('Typesense connection failed (%1): %2', $phrases);
        self::assertContains('Could not validate a connection to Typesense.', $phrases);
    }

    private function composer(): array
    {
        return json_decode((string)file_get_contents($this->root() . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return string[]
     */
    private function phpFiles(): array
    {
        $root = $this->root();
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $files = [];
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if ($file->getExtension() !== 'php' || preg_match('#^(vendor|\.[^/]+)/#', $relative)) {
                continue;
            }
            $files[] = $file->getPathname();
        }

        return $files;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
