<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\SearchAdapter\Query;

use Magento\Framework\Search\RequestInterface;

/**
 * Di-sorted composite of {@see QueryModifierInterface}s applied after {@see QueryBuilder}.
 *
 * The array is injected already sorted by di `sortOrder`; each modifier receives the
 * previous one's output, so the chain composes. Ships empty by default — the seam
 * exists for later modules to hang on to; an empty chain is a no-op.
 */
class QueryModifier implements QueryModifierInterface
{
    /**
     * @param QueryModifierInterface[] $modifiers di-sorted list of modifiers
     */
    public function __construct(
        private readonly array $modifiers = []
    ) {
        foreach ($this->modifiers as $modifier) {
            if (!$modifier instanceof QueryModifierInterface) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Query modifier must implement %s, got %s.',
                        QueryModifierInterface::class,
                        get_debug_type($modifier)
                    )
                );
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function modify(array $query, RequestInterface $request): array
    {
        foreach ($this->modifiers as $modifier) {
            $query = $modifier->modify($query, $request);
        }

        return $query;
    }
}
