<?php

namespace Meirdick\SearchChips\Tests\Support;

use Meirdick\SearchChips\Data\ModelSchema;
use Meirdick\SearchChips\Parsing\WorkersAiExtractor;

final class RecordingExtractor implements WorkersAiExtractor
{
    public function available(): bool
    {
        return true;
    }

    public function extract(string $query, ModelSchema $schema, array $config): array
    {
        return [
            'type' => ['type' => 'text', 'value' => 'video', 'confidence' => 0.99, 'p_null' => 0.01, 'span' => [0, 6]],
        ];
    }
}
