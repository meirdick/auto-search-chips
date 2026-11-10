<?php

namespace Meirdick\SearchChips;

use Illuminate\Database\Eloquent\Builder;
use Meirdick\SearchChips\Applying\EloquentApplicator;

/**
 * Adds the autoSearch() scope to a model:
 *
 *   Post::query()->autoSearch('videos by Ada above 10k views')->paginate();
 *
 * To control which fields are filterable, declare them on the model:
 *
 *   public function searchChips(ChipSchema $chips): void
 *   {
 *       $chips->text('title')->searchable();
 *       $chips->number('views');
 *   }
 *
 * Code-level use never opens the HTTP routes; those need a registered alias.
 *
 * @method static Builder<static> autoSearch(string $text)
 */
trait HasSearchChips
{
    public function scopeAutoSearch(Builder $query, string $text): Builder
    {
        $manager = app(SearchChipsManager::class);
        $result = $manager->parse($text, static::class);

        return app(EloquentApplicator::class)->apply(
            $query,
            $result->filters,
            $manager->schema(static::class),
            $result->leftover,
            $result->sort,
        );
    }
}
