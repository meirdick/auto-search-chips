<?php

namespace Meirdick\SearchChips\Contracts;

use Meirdick\SearchChips\Data\ModelSchema;
use Meirdick\SearchChips\Data\ParsedQuery;

interface Parser
{
    /**
     * Parse a raw user query into unnormalized filters for the given schema.
     * Implementations must treat this as potentially untrusted input and stay
     * within the schema's fields; the FilterNormalizer owns final validation.
     */
    public function parse(string $query, ModelSchema $schema): ParsedQuery;

    /**
     * Whether this engine is usable right now (credentials present, etc.).
     */
    public function available(): bool;
}
