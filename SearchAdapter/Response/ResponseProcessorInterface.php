<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\SearchAdapter\Response;

use Magento\Framework\Search\RequestInterface;

/**
 * A di-sorted step that may rewrite the raw Typesense search response before hits and facets are mapped.
 *
 * The response-side counterpart of {@see \DmLab\TypesenseSearch\SearchAdapter\Query\QueryModifierInterface}.
 *
 * @api
 */
interface ResponseProcessorInterface
{
    /**
     * Return the (possibly rewritten) decoded Typesense response.
     *
     * @param array<string,mixed> $response decoded Typesense search response (empty when the collection is absent)
     * @param array<string,mixed> $params final search params the response was produced for
     * @param RequestInterface $request the originating Magento request
     * @return array<string,mixed>
     */
    public function process(array $response, array $params, RequestInterface $request): array;
}
