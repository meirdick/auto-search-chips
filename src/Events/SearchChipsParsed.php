<?php

namespace Meirdick\SearchChips\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Meirdick\SearchChips\Data\FilterCollection;

/**
 * Fired after a query is parsed and normalized, whichever engine won.
 */
final class SearchChipsParsed
{
    use Dispatchable;

    public function __construct(
        public readonly string $query,
        public readonly string $alias,
        public readonly FilterCollection $filters,
        public readonly string $engine,
        public readonly string $leftover = '',
        public readonly array $warnings = [],
    ) {}
}
