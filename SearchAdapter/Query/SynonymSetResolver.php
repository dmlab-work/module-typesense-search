<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\SearchAdapter\Query;

use DmLab\TypesenseCore\Model\Synonym\SynonymSetManager;
use DmLab\TypesenseIndexer\Api\IndexNameResolverInterface;
use Magento\CatalogSearch\Model\Indexer\Fulltext;
use Magento\Framework\App\CacheInterface;
use Psr\Log\LoggerInterface;

/**
 * Name of the store's native-synonym set (`<mainAlias>_synonyms`, maintained by the indexer),
 * or null when it does not exist. Existence is cached so searches skip the round trip.
 */
class SynonymSetResolver
{
    private const SUFFIX = '_synonyms';
    private const CACHE_KEY_PREFIX = 'dmlab_typesense_synonyms_';
    private const CACHE_TAG = 'DMLAB_TYPESENSE';
    private const CACHE_LIFETIME = 300;

    /**
     * @param SynonymSetManager $synonymSetManager
     * @param IndexNameResolverInterface $indexNameResolver
     * @param CacheInterface $cache
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly SynonymSetManager $synonymSetManager,
        private readonly IndexNameResolverInterface $indexNameResolver,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Synonym set name for the store, or null when the set does not exist (or cannot be checked).
     *
     * @param int $storeId
     */
    public function getSetName(int $storeId): ?string
    {
        $name = $this->indexNameResolver->getAliasName(Fulltext::INDEXER_ID, $storeId) . self::SUFFIX;
        $key = self::CACHE_KEY_PREFIX . $name;

        $cached = $this->cache->load($key);
        if ($cached === '1' || $cached === '0') {
            return $cached === '1' ? $name : null;
        }

        try {
            $exists = $this->synonymSetManager->exists($name);
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('Could not check the Typesense synonym set "%s": %s', $name, $e->getMessage())
            );

            return null;
        }

        $this->cache->save($exists ? '1' : '0', $key, [self::CACHE_TAG], self::CACHE_LIFETIME);

        return $exists ? $name : null;
    }
}
