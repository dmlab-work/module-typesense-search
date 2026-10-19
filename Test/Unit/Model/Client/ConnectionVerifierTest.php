<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Test\Unit\Model\Client;

use DmLab\TypesenseCore\Api\ConnectionSettingsInterface;
use DmLab\TypesenseCore\Model\Client\ConnectionStatus;
use DmLab\TypesenseCore\Model\Client\HealthChecker;
use DmLab\TypesenseSearch\Model\Client\ConnectionVerifier;
use PHPUnit\Framework\TestCase;

class ConnectionVerifierTest extends TestCase
{
    public function testDelegatesGivenSettings(): void
    {
        $settings = $this->createStub(ConnectionSettingsInterface::class);
        $status = ConnectionStatus::ok('30.2', 'Connected to Typesense 30.2.');

        $health = $this->createMock(HealthChecker::class);
        $health->expects(self::once())->method('verifyConnection')->with($settings)->willReturn($status);

        self::assertSame($status, (new ConnectionVerifier($health))->verify($settings));
    }

    public function testDelegatesNullForSavedSettings(): void
    {
        $status = ConnectionStatus::unreachable('No Typesense node answered.');

        $health = $this->createMock(HealthChecker::class);
        $health->expects(self::once())->method('verifyConnection')->with(null)->willReturn($status);

        self::assertSame($status, (new ConnectionVerifier($health))->verify());
    }
}
