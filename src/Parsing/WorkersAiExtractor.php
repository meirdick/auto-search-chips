<?php

namespace Meirdick\SearchChips\Parsing;

use Meirdick\SearchChips\Data\ModelSchema;

interface WorkersAiExtractor
{
    public function available(): bool;

    /** @return array<string, array<string, mixed>> */
    public function extract(string $query, ModelSchema $schema, array $config): array;
}
