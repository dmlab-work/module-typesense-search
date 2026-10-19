<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Test\Unit\SearchAdapter\Query;

use DmLab\TypesenseCore\Model\Synonym\SynonymSetManager;
use DmLab\TypesenseIndexer\Api\IndexNameResolverInterface;
use DmLab\TypesenseSearch\SearchAdapter\Query\SynonymSetResolver;
use Magento\Framework\App\CacheInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class SynonymSetResolverTest extends TestCase
{
    private const SET = 'alias_1_synonyms';
    private const KEY = 'dmlab_typesense_synonyms_alias_1_synonyms';

    /** @var SynonymSetManager */
    private SynonymSetManager $manager;
    /** @var CacheInterface */
    private CacheInterface $cache;
    /** @var LoggerInterface */
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->manager = $this->createMock(SynonymSetManager::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function resolver(): SynonymSetResolver
    {
        $indexNameResolver = $this->createMock(IndexNameResolverInterface::class);
        $indexNameResolver->method('getAliasName')->with('catalogsearch_fulltext', 1)->willReturn('alias_1');

        return new SynonymSetResolver($this->manager, $indexNameResolver, $this->cache, $this->logger);
    }

    public function testExistingSetReturnsNameAndCachesTrue(): void
    {
        $this->cache->method('load')->with(self::KEY)->willReturn(false);
        $this->manager->expects(self::once())->method('exists')->with(self::SET)->willReturn(true);
        $this->cache->expects(self::once())->method('save')->with('1', self::KEY, ['DMLAB_TYPESENSE'], 300);

        self::assertSame(self::SET, $this->resolver()->getSetName(1));
    }

    public function testMissingSetReturnsNullAndCachesFalse(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->manager->expects(self::once())->method('exists')->willReturn(false);
        $this->cache->expects(self::once())->method('save')->with('0', self::KEY, ['DMLAB_TYPESENSE'], 300);

        self::assertNull($this->resolver()->getSetName(1));
    }

    public function testCachedValueSkipsTheManager(): void
    {
        $this->manager->expects(self::never())->method('exists');
        $this->cache->expects(self::never())->method('save');

        $this->cache->method('load')->willReturnOnConsecutiveCalls('1', '0');
        $resolver = $this->resolver();

        self::assertSame(self::SET, $resolver->getSetName(1));
        self::assertNull($resolver->getSetName(1));
    }

    public function testManagerErrorReturnsNullUncachedAndLogged(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->manager->method('exists')->willThrowException(new \RuntimeException('down'));
        $this->cache->expects(self::never())->method('save');
        $this->logger->expects(self::once())->method('warning')->with(self::stringContains('down'));

        self::assertNull($this->resolver()->getSetName(1));
    }
}
