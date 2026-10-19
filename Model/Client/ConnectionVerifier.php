<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Model\Client;

use DmLab\TypesenseCore\Api\ConnectionSettingsInterface;
use DmLab\TypesenseCore\Model\Client\ConnectionStatus;
use DmLab\TypesenseCore\Model\Client\HealthChecker;

/**
 * Thin seam over core's uncached connection check, so consumers stay unit-testable.
 */
class ConnectionVerifier
{
    /**
     * @param HealthChecker $healthChecker
     */
    public function __construct(
        private readonly HealthChecker $healthChecker
    ) {
    }

    /**
     * Verify the given connection, or the saved one when null. Never throws.
     *
     * @param ConnectionSettingsInterface|null $settings
     * @return ConnectionStatus
     */
    public function verify(?ConnectionSettingsInterface $settings = null): ConnectionStatus
    {
        return $this->healthChecker->verifyConnection($settings);
    }
}
