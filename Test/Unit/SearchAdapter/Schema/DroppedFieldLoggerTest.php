<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Test\Unit\SearchAdapter\Schema;

use DmLab\TypesenseSearch\SearchAdapter\Schema\DroppedFieldLogger;
use Magento\Framework\App\CacheInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DroppedFieldLoggerTest extends TestCase
{
    /** @var array<string,string> */
    private array $stored = [];
    /** @var array<int,array{array<int,string>,int}> */
    private array $saves = [];

    private function cache(): CacheInterface
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn (string $key) => $this->stored[$key] ?? false);
        $cache->method('save')->willReturnCallback(
            function (string $data, string $key, array $tags, ?int $lifetime): bool {
                $this->stored[$key] = $data;
                $this->saves[] = [$tags, $lifetime];

                return true;
            }
        );

        return $cache;
    }

    public function testLogsOncePerAliasAndField(): void
    {
        $messages = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(3))->method('critical')
            ->willReturnCallback(static function (string $message) use (&$messages): void {
                $messages[] = $message;
            });
        $droppedFieldLogger = new DroppedFieldLogger($this->cache(), $logger);

        $droppedFieldLogger->log('alias_1', 'color', 'color in alias_1');
        $droppedFieldLogger->log('alias_1', 'color', 'color in alias_1 again');
        $droppedFieldLogger->log('alias_1', 'size', 'size in alias_1');
        $droppedFieldLogger->log('alias_2', 'color', 'color in alias_2');

        self::assertSame(['color in alias_1', 'size in alias_1', 'color in alias_2'], $messages);
        self::assertSame([[['DMLAB_TYPESENSE'], 300]], array_values(array_unique($this->saves, SORT_REGULAR)));
    }

    public function testFieldWithCacheIdUnsafeCharactersIsKeyedSafely(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('critical');

        (new DroppedFieldLogger($this->cache(), $logger))->log('alias_1', 'boost-score(x)', 'dropped');

        self::assertMatchesRegularExpression('/^[a-z0-9_]+$/', (string)array_key_first($this->stored));
    }
}
