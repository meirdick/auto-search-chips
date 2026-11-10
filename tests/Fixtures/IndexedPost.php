<?php

namespace Meirdick\SearchChips\Tests\Fixtures;

use Laravel\Scout\Searchable;
use Meirdick\SearchChips\Schema\ChipSchema;

/** A Scout model whose index holds the chip fields, for the engine tests. */
class IndexedPost extends DeclaredPost
{
    use Searchable;

    protected function casts(): array
    {
        return [
            'views' => 'integer',
            'engagement_rate' => 'float',
            'is_featured' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function searchChips(ChipSchema $chips): void
    {
        parent::searchChips($chips);

        $chips->text('body');
        // In the index, but readable only on rows that are not featured.
        $chips->text('internal_note')->onlyWhen(['is_featured' => false]);
    }

    /** @return array<string, mixed> */
    public function toSearchableArray(): array
    {
        return [
            'id' => (string) $this->id,
            'title' => $this->title,
            'type' => $this->type,
            'views' => $this->views,
            'engagement_rate' => $this->engagement_rate,
            'is_featured' => $this->is_featured,
            'published_at' => $this->published_at?->getTimestamp(),
            'body' => $this->body,
            'internal_note' => $this->internal_note,
        ];
    }
}
