<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseSearch\Model\Client;

use MageDevGroup\TypesenseCore\Api\ConnectionSettingsInterface;
use MageDevGroup\TypesenseCore\Exception\ConfigurationException;
use MageDevGroup\TypesenseCore\Model\Config\Node;
use MageDevGroup\TypesenseIndexer\Api\EngineCode;
use Magento\AdvancedSearch\Model\Client\ClientOptionsInterface;

/**
 * Connection options for the Typesense client, read from the connection contract.
 *
 * Deliberately backed by {@see ConnectionSettingsInterface} (indexer's impl) and never
 * `Magento\Elasticsearch\Model\Config`: the ES config reads `catalog/search/elasticsearch_*`
 * paths that do not apply to Typesense. The returned array is informational (the client owns
 * connection via the contract); it never throws, so an unconfigured install degrades gracefully
 * rather than fataling inside `ClientResolver`.
 */
class ClientOptions implements ClientOptionsInterface
{
    private const DEFAULT_PROTOCOL = 'http';

    /**
     * @param ConnectionSettingsInterface $settings connection contract, bound to indexer's reader
     */
    public function __construct(
        private readonly ConnectionSettingsInterface $settings
    ) {
    }

    /**
     * Build client options from the connection contract, merging any caller-supplied overrides.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function prepareClientOptions($options = []): array
    {
        $nodes = $this->readNodes();

        $defaults = [
            'engine' => EngineCode::ENGINE,
            'protocol' => $this->protocolFrom($nodes),
            'timeout' => $this->settings->getConnectionTimeout(),
            'nodes' => array_map(
                static fn (Node $node): array => ['host' => $node->getHost(), 'port' => $node->getPort()],
                $nodes
            ),
        ];

        return array_merge($defaults, is_array($options) ? $options : []);
    }

    /**
     * Configured nodes, or empty when the connection is unset/malformed.
     *
     * @return Node[]
     */
    private function readNodes(): array
    {
        try {
            return $this->settings->getNodes();
        } catch (ConfigurationException) {
            return [];
        }
    }

    /**
     * Protocol carried by the primary node, defaulting silently when there is none.
     *
     * @param Node[] $nodes
     */
    private function protocolFrom(array $nodes): string
    {
        $primary = $nodes[0] ?? null;

        return $primary instanceof Node ? $primary->getProtocol() : self::DEFAULT_PROTOCOL;
    }
}
