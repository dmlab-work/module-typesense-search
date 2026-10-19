<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Test\Unit\SearchAdapter\Schema;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Client\TypesenseClient;
use DmLab\TypesenseSearch\SearchAdapter\Schema\SchemaReader;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class SchemaReaderTest extends TestCase
{
    private const FIELDS = [['name' => 'color', 'type' => 'int32[]', 'facet' => true]];

    /** @var TypesenseClient */
    private TypesenseClient $client;
    /** @var CacheInterface */
    private CacheInterface $cache;
    /** @var LoggerInterface */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->client = $this->createMock(TypesenseClient::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function reader(): SchemaReader
    {
        return new SchemaReader($this->client, $this->cache, new Json(), $this->logger);
    }

    public function testCacheHitSkipsTheClient(): void
    {
        $this->cache->expects(self::once())->method('load')
            ->with('dmlab_typesense_schema_alias_1')->willReturn(json_encode(self::FIELDS));
        $this->client->expects(self::never())->method('request');
        $this->cache->expects(self::never())->method('save');

        $schema = $this->reader()->get('alias_1');

        self::assertNotNull($schema);
        self::assertSame('int32[]', $schema->getType('color'));
        self::assertSame('alias_1', $schema->getAlias());
    }

    public function testCacheMissReadsAndStoresFor300Seconds(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->client->expects(self::once())->method('request')
            ->with('GET', '/collections/alias_1')
            ->willReturn(['name' => 'coll_123', 'fields' => self::FIELDS]);
        $this->cache->expects(self::once())->method('save')
            ->with(json_encode(self::FIELDS), 'dmlab_typesense_schema_alias_1', ['DMLAB_TYPESENSE'], 300);
        $this->logger->expects(self::never())->method('warning');

        $schema = $this->reader()->get('alias_1');

        self::assertNotNull($schema);
        self::assertTrue($schema->isFacet('color'));
        self::assertSame('alias_1', $schema->getAlias());
    }

    public function testCorruptCacheEntryFallsThroughToTypesense(): void
    {
        $this->cache->method('load')->willReturn('{not json');
        $this->client->expects(self::once())->method('request')
            ->willReturn(['name' => 'coll_123', 'fields' => self::FIELDS]);

        $schema = $this->reader()->get('alias_1');

        self::assertNotNull($schema);
        self::assertTrue($schema->isFacet('color'));
    }

    public function testMissingCollectionReturnsNull(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->client->method('request')
            ->willThrowException(new TypesenseException('Not found', 404, ['message' => 'Collection not found']));
        $this->cache->expects(self::once())->method('save')
            ->with('unavailable', 'dmlab_typesense_schema_alias_1', ['DMLAB_TYPESENSE'], 30);
        $this->logger->expects(self::once())->method('warning');

        self::assertNull($this->reader()->get('alias_1'));
    }

    public function testOtherErrorReturnsNull(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->client->method('request')->willThrowException(new TypesenseException('No node reachable'));
        $this->cache->expects(self::once())->method('save')
            ->with('unavailable', 'dmlab_typesense_schema_alias_1', ['DMLAB_TYPESENSE'], 30);
        $this->logger->expects(self::once())->method('warning');

        self::assertNull($this->reader()->get('alias_1'));
    }

    public function testBodyWithoutFieldsReturnsNull(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->client->method('request')->willReturn(['name' => 'coll_123']);
        $this->cache->expects(self::once())->method('save')
            ->with('unavailable', 'dmlab_typesense_schema_alias_1', ['DMLAB_TYPESENSE'], 30);
        $this->logger->expects(self::once())->method('warning');

        self::assertNull($this->reader()->get('alias_1'));
    }

    public function testCachedFailureSkipsTheClientUntilItExpires(): void
    {
        $this->cache->method('load')->with('dmlab_typesense_schema_alias_1')->willReturn('unavailable');
        $this->client->expects(self::never())->method('request');
        $this->cache->expects(self::never())->method('save');
        $this->logger->expects(self::never())->method('warning');

        self::assertNull($this->reader()->get('alias_1'));
    }

    public function testInvalidateRemovesTheKey(): void
    {
        $this->cache->expects(self::once())->method('remove')->with('dmlab_typesense_schema_alias_1');

        $this->reader()->invalidate('alias_1');
    }
}
