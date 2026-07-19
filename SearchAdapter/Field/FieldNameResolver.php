<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseSearch\SearchAdapter\Field;

use MageDevGroup\TypesenseIndexer\Api\FieldNameResolverInterface as SchemaFieldNameResolver;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Registry;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Resolves a request-side attribute code to the document field name `typesense-indexer` wrote.
 *
 * The indexer fans a few context-scoped attributes into scope-derived fields
 * (`price` → `price_<customerGroupId>_<websiteId>`, `position` → `position_category_<categoryId>`),
 * so the query side must address them by their scoped name; every other attribute maps 1:1.
 * The scope context is sourced exactly as Magento's Elasticsearch resolvers do
 * ({@see \Magento\Elasticsearch\Model\Adapter\FieldMapper\Product\FieldProvider\FieldName\Resolver\Price}
 * and its {@see \Magento\Elasticsearch\...\Resolver\Position} counterpart): customer group from the
 * customer session, website from the current store, category from the request (falling back to the
 * current category, then the store root category). Without this the query would target a `price` /
 * `position` field that no document declares, so price filtering, the price slider, price sort and
 * category-position ordering would all miss.
 */
class FieldNameResolver
{
    /**
     * @param SchemaFieldNameResolver $schemaFieldNameResolver indexer-owned name authority (the exact naming rule)
     * @param CustomerSession $customerSession source of the current customer group id (price scope)
     * @param StoreManagerInterface $storeManager source of the current website id / root category
     * @param Registry $registry source of the current category (position scope)
     */
    public function __construct(
        private readonly SchemaFieldNameResolver $schemaFieldNameResolver,
        private readonly CustomerSession $customerSession,
        private readonly StoreManagerInterface $storeManager,
        private readonly Registry $registry
    ) {
    }

    /**
     * Document field name for an attribute code within the current request context.
     *
     * @param string $field attribute code from the search request
     * @param array<string,mixed> $context optional overrides (`categoryId`) taken from the request
     */
    public function resolve(string $field, array $context = []): string
    {
        if (!$this->schemaFieldNameResolver->isContextScoped($field)) {
            return $field;
        }

        return $this->schemaFieldNameResolver->resolve($field, $this->context($field, $context));
    }

    /**
     * Build the scope context for a context-scoped attribute, honouring caller overrides.
     *
     * @param string $field
     * @param array<string,mixed> $override
     * @return array<string,mixed>
     */
    private function context(string $field, array $override): array
    {
        if ($field === 'position') {
            return ['categoryId' => (int)($override['categoryId'] ?? $this->currentCategoryId())];
        }

        return [
            'customerGroupId' => (int)$this->customerSession->getCustomerGroupId(),
            'websiteId' => (int)$this->storeManager->getStore()->getWebsiteId(),
        ];
    }

    /**
     * Current category id, falling back to the store root category (mirrors ES's Position resolver).
     */
    private function currentCategoryId(): int
    {
        $category = $this->registry->registry('current_category');
        if ($category !== null) {
            return (int)$category->getId();
        }

        return (int)$this->storeManager->getStore()->getRootCategoryId();
    }
}
