<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\SearchAdapter\Schema;

use Magento\Framework\App\CacheInterface;
use Psr\Log\LoggerInterface;

/**
 * Logs a field dropped against the alias schema as critical once per alias and field per schema cache lifetime,
 * so a stale field does not flood the log on every request.
 */
class DroppedFieldLogger
{
    private const CACHE_KEY_PREFIX = 'dmlab_typesense_dropped_';
    private const CACHE_TAG = 'DMLAB_TYPESENSE';

    /**
     * @param CacheInterface $cache
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Log the message unless this field was already reported for the alias.
     *
     * @param string $alias
     * @param string $field
     * @param string $message
     */
    public function log(string $alias, string $field, string $message): void
    {
        // Hashed: a modifier-supplied field name may hold characters a cache id rejects.
        $key = self::CACHE_KEY_PREFIX . hash('sha256', $alias . "\n" . $field);
        if ($this->cache->load($key)) {
            return;
        }

        $this->cache->save('1', $key, [self::CACHE_TAG], SchemaReader::CACHE_LIFETIME);
        $this->logger->critical($message);
    }
}
