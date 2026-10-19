<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Test\Unit\Plugin\Indexer;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Collection\AliasManager;
use DmLab\TypesenseIndexer\Api\IndexNameResolverInterface;
use DmLab\TypesenseIndexer\Model\Plugin\AttributeSaveHandler;
use DmLab\TypesenseSearch\Plugin\Indexer\SchemaCacheInvalidationPlugin;
use DmLab\TypesenseSearch\SearchAdapter\Schema\SchemaReader;
use Magento\CatalogSearch\Model\Indexer\Fulltext;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class SchemaCacheInvalidationPluginTest extends TestCase
{
    /** @var SchemaReader */
    private SchemaReader $schemaReader;

    /** @var StoreManagerInterface */
    private StoreManagerInterface $storeManager;

    protected function setUp(): void
    {
        $this->schemaReader = $this->createMock(SchemaReader::class);
        $this->storeManager = $this->createStub(StoreManagerInterface::class);
    }

    private function plugin(): SchemaCacheInvalidationPlugin
    {
        $resolver = $this->createStub(IndexNameResolverInterface::class);
        $resolver->method('getAliasName')->willReturnCallback(
            fn (string $indexerId, int $storeId): string => $indexerId . '_' . $storeId
        );

        return new SchemaCacheInvalidationPlugin($this->schemaReader, $resolver, $this->storeManager);
    }

    private function store(int $id): StoreInterface
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn($id);

        return $store;
    }

    public function testSwapInvalidatesTheSwappedAliasAndKeepsTheResult(): void
    {
        $this->schemaReader->expects($this->once())->method('invalidate')->with('alias_1');

        $result = $this->plugin()->aroundSwap(
            $this->createStub(AliasManager::class),
            fn (string $alias, string $collection): string => 'old_collection',
            'alias_1',
            'new_collection'
        );

        self::assertSame('old_collection', $result);
    }

    public function testFailedSwapCleanupStillInvalidatesTheAlias(): void
    {
        $this->schemaReader->expects($this->once())->method('invalidate')->with('alias_1');
        $this->expectException(TypesenseException::class);

        $this->plugin()->aroundSwap(
            $this->createStub(AliasManager::class),
            function (): never {
                throw new TypesenseException('drop failed', 503);
            },
            'alias_1',
            'new_collection'
        );
    }

    public function testAttributeSaveAndDeleteInvalidateEveryStoreAlias(): void
    {
        $this->storeManager->method('getStores')->willReturn([$this->store(1), $this->store(2)]);
        $invalidated = [];
        $this->schemaReader->expects($this->exactly(4))->method('invalidate')->willReturnCallback(
            function (string $alias) use (&$invalidated): void {
                $invalidated[] = $alias;
            }
        );
        $handler = $this->createStub(AttributeSaveHandler::class);
        $calls = 0;
        $proceed = function () use (&$calls): void {
            $calls++;
        };

        $this->plugin()->aroundHandleAttributeSave($handler, $proceed);
        $this->plugin()->aroundHandleAttributeDelete($handler, $proceed);

        $one = Fulltext::INDEXER_ID . '_1';
        $two = Fulltext::INDEXER_ID . '_2';
        self::assertSame(2, $calls);
        self::assertSame([$one, $two, $one, $two], $invalidated);
    }

    public function testFailedAttributeSaveStillInvalidatesEveryStoreAlias(): void
    {
        $this->storeManager->method('getStores')->willReturn([$this->store(1)]);
        $this->schemaReader->expects($this->once())->method('invalidate')->with(Fulltext::INDEXER_ID . '_1');
        $this->expectException(\RuntimeException::class);

        $this->plugin()->aroundHandleAttributeSave(
            $this->createStub(AttributeSaveHandler::class),
            function (): never {
                throw new \RuntimeException('reindex invalidation failed');
            }
        );
    }
}
