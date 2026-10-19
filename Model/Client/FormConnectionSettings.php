<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Model\Client;

use DmLab\TypesenseCore\Api\ConnectionSettingsInterface;
use DmLab\TypesenseCore\Exception\ConfigurationException;
use DmLab\TypesenseCore\Model\Config\Node;
use DmLab\TypesenseIndexer\Model\ConnectionSettings;

/**
 * Connection posted by the admin Test Connection button, falling back to the saved settings.
 */
class FormConnectionSettings implements ConnectionSettingsInterface
{
    /** `Encrypted::beforeSave` treats an all-asterisk value as "unchanged". */
    private const OBSCURED_KEY_PATTERN = '/^\*+$/';

    private const DEFAULT_PROTOCOL = 'http';

    /**
     * @param ConnectionSettingsInterface $savedSettings
     * @param array<string,mixed> $options `hostname`, `port`, `protocol`, `apiKey`, `timeout` from the form
     */
    public function __construct(
        private readonly ConnectionSettingsInterface $savedSettings,
        private readonly array $options = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getNodes(): array
    {
        $host = trim($this->stringOption('hostname'));
        if ($host === '') {
            throw new ConfigurationException('No Typesense server hostname given.');
        }

        $port = trim($this->stringOption('port'));
        if ($port === '') {
            throw new ConfigurationException('No Typesense server port given.');
        }
        if (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) {
            throw new ConfigurationException(sprintf('Invalid Typesense server port "%s".', $port));
        }

        return [new Node($host, (int)$port, $this->protocol())];
    }

    /**
     * @inheritDoc
     */
    public function getApiKey(): string
    {
        $key = trim($this->stringOption('apiKey'));
        if ($key !== '' && preg_match(self::OBSCURED_KEY_PATTERN, $key) !== 1) {
            return $key;
        }

        // The saved key never leaves for a posted host it was not configured for.
        if (!$this->targetsSavedNode()) {
            throw new ConfigurationException('Enter the API key to test a server other than the saved one.');
        }

        return $this->savedSettings->getApiKey();
    }

    /**
     * @inheritDoc
     */
    public function getConnectionTimeout(): int
    {
        $timeout = trim($this->stringOption('timeout'));

        return ctype_digit($timeout) && (int)$timeout > 0
            ? (int)$timeout
            : $this->savedSettings->getConnectionTimeout();
    }

    /**
     * @inheritDoc
     */
    public function getOperationTimeout(): int
    {
        return $this->savedSettings->getOperationTimeout();
    }

    /**
     * @inheritDoc
     */
    public function getRetryCount(): int
    {
        return $this->savedSettings->getRetryCount();
    }

    /**
     * @inheritDoc
     */
    public function getHealthCacheTtl(): int
    {
        return $this->savedSettings->getHealthCacheTtl();
    }

    /**
     * Whether the posted node is one of the saved nodes.
     *
     * @throws ConfigurationException when the posted node is invalid
     */
    private function targetsSavedNode(): bool
    {
        $posted = $this->getNodes()[0];
        try {
            $savedNodes = $this->savedSettings->getNodes();
        } catch (ConfigurationException) {
            return false;
        }

        foreach ($savedNodes as $node) {
            if (strcasecmp($node->getHost(), $posted->getHost()) === 0
                && $node->getPort() === $posted->getPort()
                && strcasecmp($node->getProtocol(), $posted->getProtocol()) === 0
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Posted protocol, `http` when empty.
     *
     * @throws ConfigurationException when not http or https
     */
    private function protocol(): string
    {
        $protocol = strtolower(trim($this->stringOption('protocol')));
        if ($protocol === '') {
            return self::DEFAULT_PROTOCOL;
        }
        if (!in_array($protocol, ConnectionSettings::ALLOWED_PROTOCOLS, true)) {
            throw new ConfigurationException(sprintf('Unsupported Typesense protocol "%s".', $protocol));
        }

        return $protocol;
    }

    /**
     * Scalar form option as a string; anything else reads as empty.
     *
     * @param string $name
     */
    private function stringOption(string $name): string
    {
        $value = $this->options[$name] ?? '';

        return is_scalar($value) ? (string)$value : '';
    }
}
