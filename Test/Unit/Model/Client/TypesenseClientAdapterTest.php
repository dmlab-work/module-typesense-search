<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Test\Unit\Model\Client;

use DmLab\TypesenseCore\Model\Client\HealthChecker;
use DmLab\TypesenseSearch\Model\Client\TypesenseClientAdapter;
use Magento\AdvancedSearch\Model\Client\ClientInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class TypesenseClientAdapterTest extends TestCase
{
    public function testImplementsClientInterface(): void
    {
        $adapter = new TypesenseClientAdapter($this->createMock(HealthChecker::class));

        self::assertInstanceOf(ClientInterface::class, $adapter);
    }

    public function testTestConnectionReturnsTrueWhenHealthy(): void
    {
        $health = $this->createMock(HealthChecker::class);
        $health->method('isHealthy')->willReturn(true);

        self::assertTrue((new TypesenseClientAdapter($health))->testConnection());
    }

    public function testTestConnectionReturnsFalseWhenUnhealthy(): void
    {
        $health = $this->createMock(HealthChecker::class);
        $health->method('isHealthy')->willReturn(false);

        self::assertFalse((new TypesenseClientAdapter($health))->testConnection());
    }
}
