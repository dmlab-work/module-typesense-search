<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Plugin\Indexer;

use DmLab\TypesenseCore\Model\Collection\AliasManager;
use DmLab\TypesenseIndexer\Api\IndexNameResolverInterface;
use DmLab\TypesenseIndexer\Model\Plugin\AttributeSaveHandler;
use DmLab\TypesenseSearch\SearchAdapter\Schema\SchemaReader;
use Magento\CatalogSearch\Model\Indexer\Fulltext;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Drops the cached alias schema when the indexer changes it: an alias swap or an in-place attribute PATCH.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class SchemaCacheInvalidationPlugin
{
    /**
     * @param SchemaReader $schemaReader
     * @param IndexNameResolverInterface $indexNameResolver
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly SchemaReader $schemaReader,
        private readonly IndexNameResolverInterface $indexNameResolver,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * The alias may point at a new collection even when the swap throws (e.g. the old collection's drop failed).
     *
     * @param AliasManager $subject
     * @param callable $proceed
     * @param string $alias
     * @param string $newCollection
     * @return string|null
     */
    public function aroundSwap(AliasManager $subject, callable $proceed, string $alias, string $newCollection): ?string
    {
        try {
            return $proceed($alias, $newCollection);
        } finally {
            $this->schemaReader->invalidate($alias);
        }
    }

    /**
     * Every store's live collection may have been PATCHed, even when the handler throws.
     *
     * @param AttributeSaveHandler $subject
     * @param callable $proceed
     */
    public function aroundHandleAttributeSave(AttributeSaveHandler $subject, callable $proceed): void
    {
        try {
            $proceed();
        } finally {
            $this->invalidateAllStores();
        }
    }

    /**
     * Every store's live collection may have been PATCHed, even when the handler throws.
     *
     * @param AttributeSaveHandler $subject
     * @param callable $proceed
     */
    public function aroundHandleAttributeDelete(AttributeSaveHandler $subject, callable $proceed): void
    {
        try {
            $proceed();
        } finally {
            $this->invalidateAllStores();
        }
    }

    /**
     * Drop the cached schema of every store's search alias.
     */
    private function invalidateAllStores(): void
    {
        foreach ($this->storeManager->getStores() as $store) {
            $this->schemaReader->invalidate(
                $this->indexNameResolver->getAliasName(Fulltext::INDEXER_ID, (int)$store->getId())
            );
        }
    }
}
