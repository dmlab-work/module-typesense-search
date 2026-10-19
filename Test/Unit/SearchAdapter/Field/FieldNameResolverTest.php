<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Test\Unit\SearchAdapter\Field;

use DmLab\TypesenseIndexer\Api\FieldNameResolverInterface;
use DmLab\TypesenseSearch\SearchAdapter\Field\FieldNameResolver;
use Magento\Catalog\Model\Category;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Registry;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class FieldNameResolverTest extends TestCase
{
    /** @var FieldNameResolverInterface */
    private FieldNameResolverInterface $schemaResolver;
    /** @var CustomerSession */
    private CustomerSession $customerSession;
    /** @var Registry */
    private Registry $registry;

    protected function setUp(): void
    {
        // Only the `@api` method may be called on the indexer resolver.
        $this->schemaResolver = $this->getMockBuilder(FieldNameResolverInterface::class)
            ->onlyMethods(['resolve'])
            ->getMock();
        $this->customerSession = $this->getMockBuilder(CustomerSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCustomerGroupId'])
            ->getMock();
        $this->registry = $this->createMock(Registry::class);
    }

    private function resolver(int $websiteId = 1, int $rootCategoryId = 2): FieldNameResolver
    {
        $store = $this->getMockBuilder(Store::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getWebsiteId', 'getRootCategoryId'])
            ->getMock();
        $store->method('getWebsiteId')->willReturn($websiteId);
        $store->method('getRootCategoryId')->willReturn($rootCategoryId);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new FieldNameResolver($this->schemaResolver, $this->customerSession, $storeManager, $this->registry);
    }

    public function testPriceUsesCustomerGroupAndWebsite(): void
    {
        $this->customerSession->method('getCustomerGroupId')->willReturn(3);
        $this->schemaResolver->expects(self::once())->method('resolve')
            ->with('price', ['customerGroupId' => 3, 'websiteId' => 2])
            ->willReturn('price_3_2');

        self::assertSame('price_3_2', $this->resolver(2)->resolve('price'));
    }

    public function testPositionHonoursCategoryOverride(): void
    {
        $this->registry->expects(self::never())->method('registry');
        $this->schemaResolver->expects(self::once())->method('resolve')
            ->with('position', ['categoryId' => 21])
            ->willReturn('position_category_21');

        self::assertSame('position_category_21', $this->resolver()->resolve('position', ['categoryId' => '21']));
    }

    public function testPositionUsesRegistryCategory(): void
    {
        $category = $this->getMockBuilder(Category::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $category->method('getId')->willReturn(15);
        $this->registry->method('registry')->with('current_category')->willReturn($category);
        $this->schemaResolver->expects(self::once())->method('resolve')
            ->with('position', ['categoryId' => 15])
            ->willReturn('position_category_15');

        self::assertSame('position_category_15', $this->resolver()->resolve('position'));
    }

    public function testPositionFallsBackToRootCategory(): void
    {
        $this->registry->method('registry')->with('current_category')->willReturn(null);
        $this->schemaResolver->expects(self::once())->method('resolve')
            ->with('position', ['categoryId' => 7])
            ->willReturn('position_category_7');

        self::assertSame('position_category_7', $this->resolver(1, 7)->resolve('position'));
    }

    public function testPlainCodeIsUnchanged(): void
    {
        $this->schemaResolver->expects(self::never())->method('resolve');

        self::assertSame('color', $this->resolver()->resolve('color'));
    }
}
