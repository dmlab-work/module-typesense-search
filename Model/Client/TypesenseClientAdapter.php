<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Model\Client;

use Magento\AdvancedSearch\Model\Client\ClientInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Magento's search `ClientInterface`, probed by the admin Test Connection button and `Setup\Validator`.
 */
class TypesenseClientAdapter implements ClientInterface
{
    /**
     * @param ConnectionVerifier $connectionVerifier
     * @param FormConnectionSettingsFactory $formSettingsFactory
     * @param array<string,mixed> $options prepared by {@see ClientOptions}; `hostname` means posted by the admin form
     */
    public function __construct(
        private readonly ConnectionVerifier $connectionVerifier,
        private readonly FormConnectionSettingsFactory $formSettingsFactory,
        private readonly array $options = []
    ) {
    }

    /**
     * Verify the posted connection, or the saved one, uncached.
     *
     * @return bool
     * @throws LocalizedException carrying the reason when the connection fails
     */
    public function testConnection(): bool
    {
        $settings = array_key_exists('hostname', $this->options)
            ? $this->formSettingsFactory->create(['options' => $this->options])
            : null;

        $status = $this->connectionVerifier->verify($settings);
        if (!$status->isOk()) {
            throw new LocalizedException(
                __('Typesense connection failed (%1): %2', $status->getState(), $status->getMessage())
            );
        }

        return true;
    }
}
