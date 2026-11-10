<?php

namespace Meirdick\SearchChips\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Meirdick\SearchChips\Attributes\Filterable;
use Meirdick\SearchChips\Attributes\Unfilterable;

#[Filterable('type', values: PostType::class, aliases: ['kind', 'format'])]
#[Filterable('engagement_rate', label: 'Engagement', unit: 'percent', scale: 0.01, aliases: ['engagement'])]
#[Filterable('views', aliases: ['view count', 'plays'], orderable: true)]
#[Filterable('title', searchable: true)]
#[Filterable('body', searchable: true)]
#[Unfilterable('internal_note')]
class Post extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => PostType::class,
            'views' => 'integer',
            'engagement_rate' => 'float',
            'is_featured' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }

    #[Unfilterable]
    public function internalAuthor(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'author_id');
    }
}
