<?php

namespace Meirdick\SearchChips\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Meirdick\SearchChips\HasSearchChips;

/** The posts table with no field list, no attributes and no enum cast. */
class PlainPost extends Model
{
    use HasSearchChips;

    protected $table = 'posts';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_featured' => 'boolean', 'published_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'author_id');
    }
}
