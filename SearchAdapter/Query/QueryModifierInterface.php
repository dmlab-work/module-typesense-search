<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseSearch\SearchAdapter\Query;

use Magento\Framework\Search\RequestInterface;

/**
 * A single, di-sorted step that may rewrite the Typesense search payload after
 * {@see QueryBuilder} has produced it.
 *
 * This is the composable seam — never a `preference` — that lets paid modules
 * (merchandising: pin/bury/boost; semantic: hybrid/vector) each modify a query
 * without excluding the other. Modifiers run in di sort order, each receiving the
 * previous modifier's output.
 */
interface QueryModifierInterface
{
    /**
     * Return the (possibly rewritten) Typesense search payload.
     *
     * @param array<string,mixed> $query the payload produced so far
     * @param RequestInterface $request the originating Magento request, for context
     * @return array<string,mixed>
     */
    public function modify(array $query, RequestInterface $request): array;
}
