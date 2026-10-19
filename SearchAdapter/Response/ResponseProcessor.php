<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\SearchAdapter\Response;

use Magento\Framework\Search\RequestInterface;

/**
 * Di-sorted composite of {@see ResponseProcessorInterface}s; each receives the previous one's output.
 * Ships empty, which is a no-op.
 */
class ResponseProcessor implements ResponseProcessorInterface
{
    /**
     * @param ResponseProcessorInterface[] $processors di-sorted list of processors
     */
    public function __construct(
        private readonly array $processors = []
    ) {
        foreach ($this->processors as $processor) {
            if (!$processor instanceof ResponseProcessorInterface) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Response processor must implement %s, got %s.',
                        ResponseProcessorInterface::class,
                        get_debug_type($processor)
                    )
                );
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function process(array $response, array $params, RequestInterface $request): array
    {
        foreach ($this->processors as $processor) {
            $response = $processor->process($response, $params, $request);
        }

        return $response;
    }
}
