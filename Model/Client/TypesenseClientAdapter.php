<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Model\Client;

use DmLab\TypesenseCore\Model\Client\HealthChecker;
use Magento\AdvancedSearch\Model\Client\ClientInterface;

/**
 * Adapts `typesense-core`'s health check to Magento's search `ClientInterface`.
 *
 * This is the seam Magento's search subsystem probes when validating the engine
 * (`ClientResolver::create()->testConnection()`). It lives here, not in core:
 * `ClientInterface` is a Magento search contract, and keeping it out of core is
 * what keeps core framework-only. Connection settings are owned by the
 * {@see \DmLab\TypesenseCore\Api\ConnectionSettingsInterface} contract; the options
 * this receives are informational, so nothing here reparses host/port.
 */
class TypesenseClientAdapter implements ClientInterface
{
    /**
     * @param HealthChecker $healthChecker
     * @param array<string,mixed> $options prepared by {@see ClientOptions}; unused, connection lives in the contract
     */
    public function __construct(
        private readonly HealthChecker $healthChecker,
        private readonly array $options = []
    ) {
    }

    /**
     * Whether Typesense is reachable and healthy.
     *
     * Delegates to core's cached, non-throwing health check, so a probe never
     * fatals and never puts a live round-trip in front of every caller.
     *
     * @return bool
     */
    public function testConnection(): bool
    {
        return $this->healthChecker->isHealthy();
    }
}
