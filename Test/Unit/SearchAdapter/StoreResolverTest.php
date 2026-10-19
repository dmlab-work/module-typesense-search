<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Test\Unit\SearchAdapter;

use DmLab\TypesenseSearch\SearchAdapter\StoreResolver;
use Magento\Framework\App\ScopeInterface;
use Magento\Framework\App\ScopeResolverInterface;
use Magento\Framework\Search\Request\Dimension;
use Magento\Framework\Search\RequestInterface;
use PHPUnit\Framework\TestCase;

class StoreResolverTest extends TestCase
{
    public function testFirstDimensionResolvesToStoreId(): void
    {
        $scope = $this->createStub(ScopeInterface::class);
        $scope->method('getId')->willReturn(4);
        $scopeResolver = $this->createMock(ScopeResolverInterface::class);
        $scopeResolver->expects(self::once())->method('getScope')->with('2')->willReturn($scope);

        $request = $this->createStub(RequestInterface::class);
        $request->method('getDimensions')->willReturn([
            'scope' => new Dimension('scope', '2'),
            'other' => new Dimension('other', '9'),
        ]);

        self::assertSame(4, (new StoreResolver($scopeResolver))->resolve($request));
    }

    public function testNoDimensionDefaultsToZero(): void
    {
        $scopeResolver = $this->createMock(ScopeResolverInterface::class);
        $scopeResolver->expects(self::never())->method('getScope');

        $request = $this->createStub(RequestInterface::class);
        $request->method('getDimensions')->willReturn([]);

        self::assertSame(0, (new StoreResolver($scopeResolver))->resolve($request));
    }
}
