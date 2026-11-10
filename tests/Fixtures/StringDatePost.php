<?php

namespace Meirdick\SearchChips\Tests\Fixtures;

/** An index that stores the date as a string, as Scout does by default. */
class StringDatePost extends IndexedPost
{
    /** @return array<string, mixed> */
    public function toSearchableArray(): array
    {
        return ['published_at' => $this->published_at?->toIso8601String()] + parent::toSearchableArray();
    }
}
