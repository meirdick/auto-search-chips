<?php

namespace Meirdick\SearchChips\Commands;

use Illuminate\Console\Command;
use Meirdick\SearchChips\Registry\ModelRegistry;

class ClearCommand extends Command
{
    protected $signature = 'search-chips:clear';

    protected $description = 'Clear the cached schemas and cached AI parses';

    public function handle(ModelRegistry $registry): int
    {
        $registry->clearCache();

        $this->components->info('Search chips caches cleared.');

        return self::SUCCESS;
    }
}
