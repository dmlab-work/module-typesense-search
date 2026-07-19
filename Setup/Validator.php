<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseSearch\Setup;

use Magento\AdvancedSearch\Model\Client\ClientResolver;
use Magento\Search\Model\SearchEngine\ValidatorInterface;

/**
 * Validates the Typesense engine connection for `SearchEngine\Validator`.
 *
 * Thin wrapper over `ClientResolver`: build the engine's client and probe it,
 * turning any failure into a human-readable error string rather than an exception,
 * exactly as OpenSearch's validator does.
 */
class Validator implements ValidatorInterface
{
    /**
     * @param ClientResolver $clientResolver
     */
    public function __construct(
        private readonly ClientResolver $clientResolver
    ) {
    }

    /**
     * @inheritDoc
     *
     * @return string[]
     */
    public function validate(): array
    {
        $errors = [];
        try {
            $client = $this->clientResolver->create();
            if (!$client->testConnection()) {
                $errors[] = 'Could not validate a connection to Typesense.'
                    . ' Verify that the configured nodes and API key are correct.';
            }
        } catch (\Exception $e) {
            $errors[] = 'Could not validate a connection to Typesense. ' . $e->getMessage();
        }

        return $errors;
    }
}
