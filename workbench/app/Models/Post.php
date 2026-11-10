<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Meirdick\SearchChips\HasSearchChips;
use Meirdick\SearchChips\Schema\ChipSchema;

class Post extends Model
{
    use HasSearchChips;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_featured' => 'boolean', 'published_at' => 'datetime'];
    }

    public function searchChips(ChipSchema $chips): void
    {
        $chips->text('title')->searchable();
        $chips->enum('type', ['video' => 'Video', 'article' => 'Article', 'podcast' => 'Podcast'])
            ->synonyms(['movie' => 'video', 'clip' => 'video', 'film' => 'video', 'essay' => 'article', 'show' => 'podcast', 'episode' => 'podcast']);
        $chips->number('views')->aliases('plays')->sortable();
        $chips->number('engagement_rate')->label('Engagement')->aliases('engagement')->unit('percent', scale: 0.01);
        $chips->boolean('is_featured')->label('Featured')->aliases('featured');
        $chips->datetime('published_at')->label('Published')->sortable();
        $chips->relation('author', 'name');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }
}
