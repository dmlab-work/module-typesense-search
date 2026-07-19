<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseSearch\Test\Unit\Model\Client;

use MageDevGroup\TypesenseSearch\Model\Client\ClientFactory;
use MageDevGroup\TypesenseSearch\Model\Client\TypesenseClientAdapter;
use Magento\AdvancedSearch\Model\Client\ClientInterface;
use Magento\Framework\ObjectManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ClientFactoryTest extends TestCase
{
    public function testCreateBuildsClientWithOptions(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->expects(self::once())
            ->method('create')
            ->with(TypesenseClientAdapter::class, ['options' => ['timeout' => 5]])
            ->willReturn($client);

        $factory = new ClientFactory($objectManager);

        self::assertSame($client, $factory->create(['timeout' => 5]));
    }

    public function testCreateRejectsNonClient(): void
    {
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('create')->willReturn(new \stdClass());

        $factory = new ClientFactory($objectManager, \stdClass::class);

        $this->expectException(\InvalidArgumentException::class);
        $factory->create();
    }
}
