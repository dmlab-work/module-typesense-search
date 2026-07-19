<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseSearch\Model\Client;

use Magento\AdvancedSearch\Model\Client\ClientFactoryInterface;
use Magento\AdvancedSearch\Model\Client\ClientInterface;
use Magento\Framework\ObjectManagerInterface;

/**
 * Builds our {@see TypesenseClientAdapter} for `ClientResolver`.
 *
 * A dedicated factory rather than Magento's generic `ClientFactory`, whose
 * `isClientOpenSearchV2()` branch is OpenSearch-only and irrelevant here.
 */
class ClientFactory implements ClientFactoryInterface
{
    /**
     * @param ObjectManagerInterface $objectManager
     * @param class-string<ClientInterface> $clientClass
     */
    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
        private readonly string $clientClass = TypesenseClientAdapter::class
    ) {
    }

    /**
     * Return a Typesense search client.
     *
     * @param array<string,mixed> $options
     * @return ClientInterface
     */
    public function create(array $options = []): ClientInterface
    {
        $client = $this->objectManager->create($this->clientClass, ['options' => $options]);
        if (!$client instanceof ClientInterface) {
            throw new \InvalidArgumentException(
                sprintf('%s must implement %s', $this->clientClass, ClientInterface::class)
            );
        }

        return $client;
    }
}
