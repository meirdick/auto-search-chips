<?php

namespace Meirdick\SearchChips\Commands;

use Illuminate\Console\Command;
use Meirdick\SearchChips\Registry\ModelRegistry;

class CacheCommand extends Command
{
    protected $signature = 'search-chips:cache';

    protected $description = 'Discover and cache the filter schema of every registered model';

    public function handle(ModelRegistry $registry): int
    {
        if (! config('search-chips.cache.schema')) {
            $this->components->warn('The schema cache is off. Set SEARCH_CHIPS_SCHEMA_CACHE=true to use it.');

            return self::SUCCESS;
        }

        $registry->clearCache();

        foreach ($registry->all() as $registered) {
            $schema = $registry->refreshSchema($registered);

            $this->components->twoColumnDetail($registered->alias, count($schema->fields).' fields');
        }

        $this->components->info('Search chips schemas cached.');

        return self::SUCCESS;
    }
}
