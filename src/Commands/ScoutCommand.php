<?php

namespace Meirdick\SearchChips\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Meirdick\SearchChips\Applying\ScoutApplicator;
use Meirdick\SearchChips\Data\FieldSchema;
use Meirdick\SearchChips\SearchChipsManager;

class ScoutCommand extends Command
{
    protected $signature = 'search-chips:scout {alias : A registered model alias}';

    protected $description = 'Print the filterable and sortable attributes a Scout index needs for search chips';

    public function handle(SearchChipsManager $manager): int
    {
        $models = $manager->models();
        $alias = (string) $this->argument('alias');

        if (! isset($models[$alias])) {
            $this->components->error("Unknown alias [{$alias}].");

            return self::FAILURE;
        }

        $class = $models[$alias]->modelClass;

        if (! in_array('Laravel\\Scout\\Searchable', class_uses_recursive($class), true)) {
            $this->components->error("{$class} does not use Laravel\\Scout\\Searchable.");

            return self::FAILURE;
        }

        $schema = $manager->schema($alias);
        $indexed = array_filter($schema->fields, fn (FieldSchema $field) => ScoutApplicator::indexable($field));
        $filterable = array_keys($indexed);
        $sortable = array_values(array_intersect($schema->orderable, $filterable));
        $database = array_diff_key($schema->fields, $indexed);

        $this->components->info($class);
        $this->components->twoColumnDetail('Filterable', implode(', ', $filterable) ?: '-');
        $this->components->twoColumnDetail('Sortable', implode(', ', $sortable) ?: '-');
        $this->components->twoColumnDetail('Database only', implode(', ', array_keys($database)) ?: '-');
        $this->newLine();
        $this->line('toSearchableArray() must return these fields. Store dates as Unix timestamps and yes/no fields as booleans.');
        $this->line('The collection and database engines need no index settings.');
        $this->newLine();

        $this->line('Meilisearch, in config/scout.php under meilisearch.index-settings, then run php artisan scout:sync-index-settings:');
        $this->newLine();
        $this->line("    \\{$class}::class => [");
        $this->line("        'filterableAttributes' => ".$this->list($filterable).',');
        $this->line("        'sortableAttributes' => ".$this->list($sortable).',');
        $this->line('        // Totals above 1,000 need a higher limit:');
        $this->line("        // 'pagination' => ['maxTotalHits' => 10000],");
        $this->line('    ],');
        $this->newLine();

        $this->line('Typesense, in config/scout.php under typesense.model-settings:');
        $this->newLine();
        $this->line("    \\{$class}::class => [");
        $this->line("        'collection-schema' => [");
        $this->line("            'fields' => [");
        $this->line("                ['name' => 'id', 'type' => 'string'],");

        foreach ($this->typesenseFields(new $class, $indexed, $sortable) as $field) {
            $this->line('                '.$field.',');
        }

        $this->line('            ],');
        $this->line('        ],');

        $searchable = array_values(array_intersect($schema->searchable, $filterable));

        if ($searchable !== []) {
            $this->line("        'search-parameters' => ['query_by' => '".implode(',', $searchable)."'],");
        }

        $this->line('    ],');

        return self::SUCCESS;
    }

    /**
     * One collection-schema field per indexed chip field. The column type
     * picks int64 or float; a nullable column makes the field optional.
     *
     * @param  array<string, FieldSchema>  $fields
     * @param  array<int, string>  $sortable
     * @return array<int, string>
     */
    private function typesenseFields(Model $model, array $fields, array $sortable): array
    {
        try {
            $columns = collect($model->getConnection()->getSchemaBuilder()->getColumns($model->getTable()))->keyBy('name');
        } catch (\Throwable) {
            $columns = collect();
        }

        $lines = [];

        foreach ($fields as $name => $field) {
            $column = $columns->get($name);
            $integer = $column !== null && preg_match('/int/i', $column['type_name']) === 1;

            $type = match ($field->kind) {
                'number' => $integer ? 'int64' : 'float',
                'date', 'datetime' => 'int64',
                'boolean' => 'bool',
                default => is_int(($field->values ?? [null])[0] ?? null) ? 'int64' : 'string',
            };

            $line = "['name' => '{$name}', 'type' => '{$type}'";
            $line .= ($column['nullable'] ?? true) ? ", 'optional' => true" : '';
            $line .= in_array($name, $sortable, true) ? ", 'sort' => true" : '';

            $lines[] = $line.']';
        }

        return $lines;
    }

    /** @param  array<int, string>  $values */
    private function list(array $values): string
    {
        return $values === [] ? '[]' : "['".implode("', '", $values)."']";
    }
}
