<?php

namespace Meirdick\SearchChips\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Meirdick\SearchChips\HasSearchChips;
use Meirdick\SearchChips\Schema\ChipSchema;

/** A model that declares its own filters. Nothing else on the table is reachable. */
class DeclaredPost extends Model
{
    use HasSearchChips;

    protected $table = 'posts';

    protected $guarded = [];

    public function searchChips(ChipSchema $chips): void
    {
        $chips->text('title')->searchable();
        $chips->enum('type', ['video' => 'Video', 'article' => 'Article', 'podcast' => 'Podcast'])->synonyms(['movie' => 'video', 'show' => 'podcast']);
        $chips->number('views')->aliases('plays')->sortable();
        $chips->number('engagement_rate')->label('Engagement')->aliases('engagement')->unit('percent', scale: 0.01);
        $chips->boolean('is_featured')->label('Featured')->aliases('featured');
        $chips->datetime('published_at')->sortable();
        $chips->relation('author', 'name');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }
}
