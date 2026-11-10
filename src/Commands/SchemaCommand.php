<?php

namespace Meirdick\SearchChips\Commands;

use Illuminate\Console\Command;
use Meirdick\SearchChips\Data\ModelSchema;
use Meirdick\SearchChips\SearchChipsManager;

class SchemaCommand extends Command
{
    protected $signature = 'search-chips:schema
        {alias? : A registered model alias}
        {--json : Output raw JSON instead of a table}
        {--stub : Print a searchChips() method for the model, as a starting point}';

    protected $description = 'Show the discovered filter schema for registered models';

    public function handle(SearchChipsManager $manager): int
    {
        $models = $manager->models();

        if ($models === []) {
            $this->components->info('No models registered. Add them to config("search-chips.models") or SearchChips::register().');

            return self::SUCCESS;
        }

        $alias = $this->argument('alias');

        if ($alias === null) {
            $this->table(['Alias', 'Model'], collect($models)->map(fn ($m) => [$m->alias, $m->modelClass])->all());

            return self::SUCCESS;
        }

        if (! isset($models[$alias])) {
            $this->components->error("Unknown alias [{$alias}].");

            return self::FAILURE;
        }

        $schema = $manager->schema($alias);

        if ($this->option('stub')) {
            $this->line($this->stub($schema));

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'model' => $schema->modelClass,
                'version' => $schema->version(),
                'searchable' => $schema->searchable,
                'orderable' => $schema->orderable,
                'fields' => collect($schema->fields)->map(fn ($f) => [
                    'kind' => $f->kind,
                    'label' => $f->displayLabel(),
                    'aliases' => $f->aliases,
                    'values' => $f->values,
                    'unit' => $f->unit,
                    'relation' => $f->relation,
                ])->all(),
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->components->info($schema->modelClass.' — schema v'.$schema->version());

        $this->table(
            ['Field', 'Kind', 'Searchable', 'Orderable', 'Relation', 'Values'],
            collect($schema->fields)->map(fn ($f) => [
                $f->name,
                $f->kind,
                in_array($f->name, $schema->searchable, true) ? '✓' : '',
                in_array($f->name, $schema->orderable, true) ? '✓' : '',
                $f->relation ? $f->relation.'.'.$f->relationColumn : '',
                $f->values ? implode(', ', array_map(fn ($v) => (string) $v, array_slice((array) $f->values, 0, 5))) : '',
            ])->all(),
        );

        return self::SUCCESS;
    }

    /** The discovered schema as code, to paste into the model and then trim. */
    private function stub(ModelSchema $schema): string
    {
        $lines = [];

        foreach ($schema->fields as $field) {
            if ($field->primary) {
                continue;
            }

            $call = match (true) {
                $field->relation !== null => "relation('{$field->relation}', '{$field->relationColumn}')",
                $field->kind === 'enum' || $field->values !== null => "enum('{$field->name}', ".var_export(array_values((array) $field->values), true).')',
                default => "{$field->kind}('{$field->name}')",
            };

            $call = preg_replace('/\s+/', ' ', str_replace(['array (', ',)'], ['[', ']'], preg_replace('/\d+ => /', '', $call)));
            $call = str_replace([', ]', '[ ', ' )'], [']', '[', ']'], $call);

            if ($field->aliases !== [] && $field->relation === null) {
                $call .= "->aliases('".implode("', '", $field->aliases)."')";
            }

            if (in_array($field->name, $schema->searchable, true)) {
                $call .= '->searchable()';
            }

            if (in_array($field->name, $schema->orderable, true)) {
                $call .= '->sortable()';
            }

            $lines[] = "        \$chips->{$call};";
        }

        return implode("\n", [
            '    public function searchChips(\\Meirdick\\SearchChips\\Schema\\ChipSchema $chips): void',
            '    {',
            ...$lines,
            '    }',
        ]);
    }
}
