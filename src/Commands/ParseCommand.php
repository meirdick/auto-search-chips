<?php

namespace Meirdick\SearchChips\Commands;

use Illuminate\Console\Command;
use Meirdick\SearchChips\Exceptions\UnknownModelAliasException;
use Meirdick\SearchChips\SearchChipsManager;

class ParseCommand extends Command
{
    protected $signature = 'search-chips:parse
        {alias : A registered model alias}
        {query* : The natural-language query}';

    protected $description = 'Parse a query into chips and show the engines\' reading';

    public function handle(SearchChipsManager $manager): int
    {
        $alias = $this->argument('alias');
        $query = implode(' ', (array) $this->argument('query'));

        try {
            $result = $manager->parse($query, $alias);
        } catch (UnknownModelAliasException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Engine: '.$result->engine);

        if ($result->filters->isEmpty()) {
            $this->components->warn('No chips extracted.');
        }

        foreach ($result->filters as $filter) {
            $confidence = $filter->confidence < 1.0 ? sprintf(' (%d%%)', (int) round($filter->confidence * 100)) : '';
            $suggested = $filter->suggested ? ' [suggested]' : '';

            $this->line("  • <info>{$filter->label}</info>{$confidence}{$suggested}");
        }

        if ($result->leftover !== '') {
            $this->components->twoColumnDetail('Leftover', $result->leftover);
        }

        if ($result->sort !== null) {
            $this->components->twoColumnDetail('Sort', $result->sort['field'].' '.$result->sort['direction']);
        }

        foreach ($result->warnings as $warning) {
            $this->components->warn($warning);
        }

        return self::SUCCESS;
    }
}
