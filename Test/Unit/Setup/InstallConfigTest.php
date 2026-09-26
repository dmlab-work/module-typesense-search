<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Test\Unit\Setup;

use DmLab\TypesenseIndexer\Model\ConnectionSettings;
use DmLab\TypesenseSearch\Setup\InstallConfig;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Search\Setup\InstallConfigInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class InstallConfigTest extends TestCase
{
    public function testImplementsInstallConfigInterface(): void
    {
        self::assertInstanceOf(
            InstallConfigInterface::class,
            new InstallConfig($this->createMock(WriterInterface::class))
        );
    }

    public function testWritesMappedOptionsToFullPaths(): void
    {
        $writer = $this->createMock(WriterInterface::class);
        $saved = [];
        $writer->method('save')->willReturnCallback(
            static function ($path, $value) use (&$saved): void {
                $saved[$path] = $value;
            }
        );

        (new InstallConfig($writer))->configure([
            'search-engine' => 'typesense',
            'typesense-server-hostname' => 'typesense',
            'typesense-server-port' => '8108',
            'typesense-index-prefix' => 'shop',
            'typesense-protocol' => 'https',
        ]);

        self::assertSame('typesense', $saved['catalog/search/engine']);
        self::assertSame('typesense', $saved[ConnectionSettings::XML_PATH_SERVER_HOSTNAME]);
        self::assertSame('8108', $saved[ConnectionSettings::XML_PATH_SERVER_PORT]);
        self::assertSame('shop', $saved[ConnectionSettings::XML_PATH_INDEX_PREFIX]);
        self::assertSame('https', $saved[ConnectionSettings::XML_PATH_PROTOCOL]);
    }

    public function testSkipsNullAndUnmappedOptions(): void
    {
        $writer = $this->createMock(WriterInterface::class);
        $writer->expects(self::once())
            ->method('save')
            ->with('catalog/search/engine', 'typesense');

        (new InstallConfig($writer))->configure([
            'search-engine' => 'typesense',
            'typesense-server-hostname' => null,
            'unrelated-option' => 'ignored',
        ]);
    }
}
