<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\SearchAdapter\Query;

use Magento\Framework\Search\RequestInterface;

/**
 * A single, di-sorted step that may rewrite the Typesense search payload.
 *
 * The composable seam — never a `preference` — that lets paid modules (merchandising, semantic)
 * each modify a query without excluding the other. Runs after {@see QueryBuilder} and the
 * aggregation builder, so the payload already carries `facet_by`/`max_facet_values`; sort/facet
 * clauses on fields the schema lacks are dropped afterwards. Each modifier receives the previous
 * one's output. Comma-list params already set (`synonym_sets`, `pinned_hits`, `hidden_hits`) must be
 * appended to, not overwritten.
 *
 * @api
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
