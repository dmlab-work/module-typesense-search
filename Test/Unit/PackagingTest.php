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

    public function testComposerSupportsPhp82AndPhpunit11(): void
    {
        $composer = $this->composer();

        self::assertSame('~8.2.0||~8.3.0||~8.4.0||~8.5.0', $composer['require']['php']);
        self::assertSame('^11.5||^12.5', $composer['require-dev']['phpunit/phpunit']);
    }

    public function testComposerCarriesDiscoveryMetadata(): void
    {
        $composer = $this->composer();
        $repo = 'https://github.com/dmlab-work/module-typesense-search';

        self::assertContains('typesense', $composer['keywords']);
        self::assertContains('magento2-module', $composer['keywords']);
        self::assertSame($repo, $composer['homepage']);
        self::assertSame($repo . '/issues', $composer['support']['issues']);
        self::assertSame($repo, $composer['support']['source']);
        self::assertArrayHasKey('dmlab/module-typesense-instant-search', $composer['suggest']);
    }

    public function testComposerKeepsVersionKey(): void
    {
        self::assertArrayHasKey('version', $this->composer());
    }

    public function testChangelogTopSectionMatchesComposerVersion(): void
    {
        $changelog = (string)file_get_contents($this->root() . '/CHANGELOG.md');

        self::assertSame(1, preg_match('/^## (\S+)$/m', $changelog, $match));
        self::assertSame($this->composer()['version'], $match[1]);
    }

    public function testCiCoversPhp82To85AndSkipsDownloadCounting(): void
    {
        $ci = (string)file_get_contents($this->root() . '/.github/workflows/ci.yml');

        self::assertStringContainsString("php: ['8.2', '8.3', '8.4', '8.5']", $ci);
        self::assertSame(2, substr_count($ci, '- uses: ramsey/composer-install@'));
        self::assertSame(2, preg_match_all(
            '/- run: composer config notify-on-install false\s+- uses: ramsey\/composer-install@/',
            $ci
        ));
    }

    public function testReadmeSectionOrderAndScreenshot(): void
    {
        $readme = (string)file_get_contents($this->root() . '/README.md');
        $screenshot = '.github/images/search-results.jpeg';

        self::assertStringContainsString('](' . $screenshot . ')', $readme);
        self::assertFileExists($this->root() . '/' . $screenshot);

        preg_match_all('/^## (.+)$/m', $readme, $matches);
        self::assertSame(
            [
                'Install', 'Requirements', 'Hyvä', 'Select Typesense', 'Covered', 'Known differences',
                'Hosting', 'Integration tests', 'Architecture', 'License',
            ],
            $matches[1]
        );
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
