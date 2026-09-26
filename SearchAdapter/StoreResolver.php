<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\SearchAdapter;

use Magento\Framework\App\ScopeResolverInterface;
use Magento\Framework\Search\RequestInterface;

/**
 * Resolves a search request's first dimension to a store id.
 *
 * Shared by {@see Adapter} and {@see Query\QueryBuilder} so the request→store mapping
 * lives in one place and the two never drift.
 */
class StoreResolver
{
    /**
     * @param ScopeResolverInterface $scopeResolver resolves the request dimension to a store id
     */
    public function __construct(
        private readonly ScopeResolverInterface $scopeResolver
    ) {
    }

    /**
     * Store id from the request's first dimension, defaulting to the default store.
     *
     * @param RequestInterface $request
     */
    public function resolve(RequestInterface $request): int
    {
        $dimension = current($request->getDimensions());
        if ($dimension === false) {
            return 0;
        }

        return (int)$this->scopeResolver->getScope($dimension->getValue())->getId();
    }
}
