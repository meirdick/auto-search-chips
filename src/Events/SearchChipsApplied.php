<?php

namespace Meirdick\SearchChips\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Meirdick\SearchChips\Data\FilterCollection;

/**
 * Fired after filters have been applied to the underlying query.
 */
final class SearchChipsApplied
{
    use Dispatchable;

    public function __construct(
        public readonly string $query,
        public readonly string $alias,
        public readonly FilterCollection $filters,
        public readonly int $count,
        public readonly string $engine,
    ) {}
}
