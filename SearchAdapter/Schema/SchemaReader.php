<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\SearchAdapter\Schema;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Client\TypesenseClient;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads (and caches) the declared fields of the collection behind an alias.
 *
 * Read-only: Typesense resolves the alias server-side. Null means "schema unknown"
 * (Typesense down, alias missing); callers then keep the untyped behaviour. A failed read is
 * cached for 30 s, so a missing alias or a down node costs one `GET` per alias per 30 s, not per search.
 */
class SchemaReader
{
    private const CACHE_KEY_PREFIX = 'dmlab_typesense_schema_';
    private const CACHE_TAG = 'DMLAB_TYPESENSE';
    public const CACHE_LIFETIME = 300;
    private const FAILURE_LIFETIME = 30;
    private const FAILURE_MARKER = 'unavailable';

    /**
     * @param TypesenseClient $client
     * @param CacheInterface $cache
     * @param SerializerInterface $serializer
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly TypesenseClient $client,
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Schema of the collection behind the alias, or null when it cannot be read.
     *
     * @param string $alias
     */
    public function get(string $alias): ?CollectionSchema
    {
        $cached = $this->cache->load($this->cacheKey($alias));
        if ($cached === self::FAILURE_MARKER) {
            return null;
        }
        if (is_string($cached) && $cached !== '') {
            try {
                $fields = $this->serializer->unserialize($cached);
            } catch (\InvalidArgumentException) {
                $fields = null;
            }
            if (is_array($fields)) {
                return new CollectionSchema($fields, $alias);
            }
        }

        try {
            $body = $this->client->request('GET', '/collections/' . $alias);
        } catch (TypesenseException $e) {
            $this->logger->warning(
                sprintf('Could not read the Typesense schema of "%s": %s', $alias, $e->getMessage())
            );
            $this->rememberFailure($alias);

            return null;
        }

        if (!isset($body['fields']) || !is_array($body['fields'])) {
            $this->logger->warning(sprintf('Typesense schema of "%s" has no fields list.', $alias));
            $this->rememberFailure($alias);

            return null;
        }

        $fields = array_values($body['fields']);
        $this->cache->save(
            $this->serializer->serialize($fields),
            $this->cacheKey($alias),
            [self::CACHE_TAG],
            self::CACHE_LIFETIME
        );

        return new CollectionSchema($fields, $alias);
    }

    /**
     * Drop the cached schema so the next read hits Typesense.
     *
     * @param string $alias
     */
    public function invalidate(string $alias): void
    {
        $this->cache->remove($this->cacheKey($alias));
    }

    /**
     * Cache the failed read so callers skip Typesense until it expires or the schema is invalidated.
     *
     * @param string $alias
     */
    private function rememberFailure(string $alias): void
    {
        $this->cache->save(self::FAILURE_MARKER, $this->cacheKey($alias), [self::CACHE_TAG], self::FAILURE_LIFETIME);
    }

    /**
     * Cache key of the alias schema.
     *
     * @param string $alias
     */
    private function cacheKey(string $alias): string
    {
        return self::CACHE_KEY_PREFIX . $alias;
    }
}
