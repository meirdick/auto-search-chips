<?php

namespace Meirdick\SearchChips\Discovery;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Meirdick\SearchChips\Attributes\Filterable;
use Meirdick\SearchChips\Attributes\Unfilterable;
use Meirdick\SearchChips\Data\FieldSchema;
use Meirdick\SearchChips\Data\ModelSchema;
use Meirdick\SearchChips\Data\RelationSchema;
use Meirdick\SearchChips\Schema\ChipSchema;
use ReflectionClass;
use ReflectionMethod;

/**
 * Builds the filter schema of a model. A model that declares
 * searchChips(ChipSchema $chips) exposes exactly the fields it lists. Any
 * other model is discovered: secret-ish columns and hidden fields are
 * excluded, foreign keys surface as relations, and #[Filterable] attributes
 * add metadata.
 */
class SchemaDiscovery
{
    /** A column name that says it holds a rate. */
    private const RATIO_SUFFIX = '/_(rate|ratio|percent|percentage|pct)$/';

    /** Columns never offered to engines unless explicitly opted in. */
    public const EXCLUDE_PATTERN = '/password|passwd|token|secret|remember|api[_-]?key|[_-]hash|salt|private|internal/';

    private const NAME_COLUMNS = ['name', 'title', 'label', 'username', 'slug'];

    private const SEARCHABLE_HINTS = ['title', 'name', 'body', 'content', 'description', 'summary', 'subject', 'caption', 'text'];

    /**
     * @param  array<string, mixed>  $overrides  per-model config: search, orderable, relations, exclude, fields
     */
    public function discover(string $modelClass, array $overrides = []): ModelSchema
    {
        /** @var Model $model */
        $model = new $modelClass;

        if (method_exists($model, 'searchChips')) {
            return $this->declared($model, $overrides);
        }

        $casts = $model->getCasts();
        $hidden = array_flip($model->getHidden());
        $keyName = $model->getKeyName();

        $types = $this->columnTypes($model);
        $columns = array_keys($types);

        $fields = [];

        foreach ($columns as $column) {
            if ($column !== $keyName && isset($hidden[$column])) {
                continue;
            }

            // Foreign keys are surfaced as relations instead of text filters.
            if ($column !== $keyName && Str::endsWith($column, '_id')) {
                continue;
            }

            if ($column !== $keyName && preg_match(self::EXCLUDE_PATTERN, $column)) {
                continue;
            }

            // The soft-delete marker is plumbing, not a filter.
            if (method_exists($model, 'getDeletedAtColumn') && $column === $model->getDeletedAtColumn()) {
                continue;
            }

            $kind = $this->kindForColumn($casts[$column] ?? null, $types[$column] ?? null);

            if ($kind === null) {
                continue; // arrays, json, and unknown casts are not filterable
            }

            $enum = $this->enumValues($casts[$column] ?? null);
            $sampled = $enum === null ? $this->sampledValues($model, $column, $kind) : null;
            $ratio = $kind === 'number' && $column !== $keyName && $this->holdsRatio($model, $column);

            $fields[$column] = new FieldSchema(
                name: $column,
                kind: $kind,
                label: $this->discoveredLabel($column, $kind),
                // "engagement above 5%" names the column engagement_rate.
                aliases: $ratio ? [str_replace('_', ' ', (string) preg_replace(self::RATIO_SUFFIX, '', $column))] : [],
                values: $enum ?? $sampled,
                valueSource: $enum !== null ? 'enum' : 'sampled',
                valueLabels: $sampled !== null ? array_combine($sampled, array_map(fn (string $value) => Str::headline($value), $sampled)) : [],
                unit: $ratio ? 'percent' : null,
                scale: $ratio ? 0.01 : null,
                // No field list says what may be sorted, so a number column may: "most viewed".
                orderable: $kind === 'number' && $column !== $keyName,
                primary: $column === $keyName,
            );
        }

        $fields = $this->applyAttributes($modelClass, $fields, $columns, $keyName);

        [$fields, $relations] = $this->discoverRelations($model, $fields, $overrides);

        $fields = $this->applyOverrides($fields, $overrides);

        return $this->schema($modelClass, $fields, $relations, $overrides);
    }

    /**
     * The schema of a model that declares its own fields. No table
     * introspection runs, so this path needs no database at all.
     */
    private function declared(Model $model, array $overrides): ModelSchema
    {
        $chips = new ChipSchema;
        call_user_func([$model, 'searchChips'], $chips);

        $fields = $chips->fields();
        $relations = [];

        foreach ($fields as $name => $field) {
            if ($field->relation === null) {
                continue;
            }

            try {
                $related = $model->{$field->relation}()->getRelated();
            } catch (\Throwable) {
                unset($fields[$name]); // names a relation the model does not have

                continue;
            }

            $relations[$field->relation] = new RelationSchema($field->relation, $related::class, $field->relationColumn);
        }

        return $this->schema($model::class, $this->applyOverrides($fields, $overrides), $relations, $overrides);
    }

    /**
     * @param  array<string, FieldSchema>  $fields
     * @param  array<string, RelationSchema>  $relations
     */
    private function schema(string $modelClass, array $fields, array $relations, array $overrides): ModelSchema
    {
        $searchable = $this->resolveSearchable($fields, $overrides);
        $orderable = $this->resolveOrderable($fields, $overrides);

        return new ModelSchema(
            modelClass: $modelClass,
            fields: $fields,
            relations: array_intersect_key($relations, array_flip(array_filter(array_map(fn (FieldSchema $f) => $f->relation, $fields)))),
            searchable: $searchable,
            orderable: $orderable,
        );
    }

    /** @return array<int, string> */
    private function columns(Model $model): array
    {
        return array_keys($this->columnTypes($model));
    }

    /**
     * Column name => declared type, from one schema query per table.
     *
     * @return array<string, string>
     */
    private function columnTypes(Model $model): array
    {
        try {
            $columns = $model->getConnection()->getSchemaBuilder()->getColumns($model->getTable());
        } catch (\Throwable) {
            return [];
        }

        $types = [];

        foreach ($columns as $column) {
            $types[$column['name']] = strtolower($column['type_name']);
        }

        return $types;
    }

    /**
     * A few distinct values of a low-cardinality text column. Off by default:
     * the values are real row data and reach the AI engine's prompt.
     */
    private function sampledValues(Model $model, string $column, string $kind): ?array
    {
        if ($kind !== 'text' || ! config('search-chips.discovery.sample_values', false)) {
            return null;
        }

        try {
            // One more than the limit: a column with more values is free text, not a value list.
            $values = $model->newQuery()->distinct()->limit(9)->pluck($column)
                ->filter(fn ($value) => is_scalar($value) && $value !== '')
                ->map(fn ($value) => (string) $value)
                ->values()
                ->all();
        } catch (\Throwable) {
            return null;
        }

        return count($values) >= 2 && count($values) <= 8 ? $values : null;
    }

    /** "is_featured" reads as "Featured", "published_at" as "Published". */
    private function discoveredLabel(string $column, string $kind): ?string
    {
        $short = match ($kind) {
            'boolean' => preg_replace('/^(is|has)_/', '', $column),
            'date', 'datetime' => preg_replace('/_(at|on)$/', '', $column),
            default => $column,
        };

        return $short === $column || $short === '' || $short === null ? null : Str::headline($short);
    }

    /**
     * A column such as engagement_rate that holds 0.08 for 8%. The name must
     * say it is a rate, and no row may hold a value above 1.
     */
    private function holdsRatio(Model $model, string $column): bool
    {
        if (! preg_match(self::RATIO_SUFFIX, $column)) {
            return false;
        }

        try {
            $highest = $model->newQuery()->max($column);
        } catch (\Throwable) {
            return false;
        }

        return is_numeric($highest) && $highest > 0 && $highest <= 1;
    }

    private function enumValues(?string $cast): ?array
    {
        if (is_string($cast) && class_exists($cast) && is_subclass_of($cast, \BackedEnum::class)) {
            return array_map(fn (\BackedEnum $case) => $case->value, $cast::cases());
        }

        return null;
    }

    private function kindForColumn(?string $cast, ?string $type): ?string
    {
        if ($cast !== null) {
            $base = $cast;

            if (Str::startsWith($cast, 'decimal:')) {
                return 'number';
            }

            if (Str::startsWith($cast, 'custom_datetime') || Str::startsWith($cast, 'immutable_custom_datetime')) {
                return 'datetime';
            }

            if (class_exists($cast) && is_subclass_of($cast, \BackedEnum::class)) {
                return 'enum';
            }

            if (Str::startsWith($cast, 'encrypted:')) {
                return null; // secrets stay out of filter schemas
            }

            return match ($base) {
                'int', 'integer', 'float', 'double', 'real' => 'number',
                'bool', 'boolean' => 'boolean',
                'date' => 'date',
                'datetime', 'timestamp' => 'datetime',
                'string' => 'text',
                default => null, // array, json, collection, object, AsCollection, ...
            };
        }

        // Uncast columns: use the connection's declared type.
        return match ($type) {
            'integer', 'int', 'int2', 'int4', 'int8', 'bigint', 'smallint', 'mediumint', 'tinyint',
            'numeric', 'decimal', 'float', 'float4', 'float8', 'double', 'real', 'money' => 'number',
            'boolean', 'bool', 'bit' => 'boolean',
            'date' => 'date',
            'datetime', 'datetime2', 'timestamp', 'timestamptz' => 'datetime',
            'string', 'text', 'varchar', 'nvarchar', 'char', 'bpchar', 'tinytext', 'mediumtext', 'longtext', 'citext' => 'text',
            default => null,
        };
    }

    /**
     * Fold class-level #[Filterable] / #[Unfilterable] attributes into the
     * discovered fields. Attributes live on the class, not properties:
     * a declared model property would shadow Eloquent's magic accessors.
     *
     * @param  array<string, FieldSchema>  $fields
     * @return array<string, FieldSchema>
     */
    private function applyAttributes(string $modelClass, array $fields, array $columns, string $keyName): array
    {
        $reflection = new ReflectionClass($modelClass);

        foreach ($reflection->getAttributes(Unfilterable::class) as $attribute) {
            /** @var Unfilterable $unfilterable */
            $unfilterable = $attribute->newInstance();

            if ($unfilterable->field === null) {
                return []; // the model opted out entirely
            }

            unset($fields[$unfilterable->field]);
        }

        foreach ($reflection->getAttributes(Filterable::class) as $attribute) {
            /** @var Filterable $filterable */
            $filterable = $attribute->newInstance();
            $name = $filterable->field;

            if (! isset($fields[$name]) && ! in_array($name, $columns) && ! str_contains($name, '.')) {
                continue; // attribute names a column the table doesn't have
            }

            $existing = $fields[$name] ?? new FieldSchema(name: $name, kind: 'text');

            $fields[$name] = new FieldSchema(
                name: $name,
                kind: $existing->kind,
                label: $filterable->label ?? $existing->label,
                aliases: [...$existing->aliases, ...$filterable->aliases],
                values: $this->attributeValues($filterable, $existing),
                valueLabels: $existing->valueLabels,
                unit: $filterable->unit ?? $existing->unit,
                scale: $filterable->scale ?? $existing->scale,
                searchable: $filterable->searchable || $existing->searchable,
                orderable: $filterable->orderable || $existing->orderable,
                primary: $existing->primary,
            );
        }

        return $fields;
    }

    private function attributeValues(Filterable $filterable, FieldSchema $existing): ?array
    {
        if ($filterable->values === null) {
            return $existing->values;
        }

        if (is_string($filterable->values) && is_subclass_of($filterable->values, \BackedEnum::class)) {
            return array_map(fn (\BackedEnum $case) => $case->value, $filterable->values::cases());
        }

        return $filterable->values;
    }

    /**
     * Reflect public relation methods and add "relation.name" filter fields.
     *
     * @param  array<string, FieldSchema>  $fields
     * @return array{0: array<string, FieldSchema>, 1: array<string, RelationSchema>}
     */
    private function discoverRelations(Model $model, array $fields, array $overrides): array
    {
        $relations = [];
        $reflection = new ReflectionClass($model);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->getDeclaringClass()->getName() === Model::class || $method->getNumberOfParameters() > 0) {
                continue;
            }

            if ($method->getAttributes(Unfilterable::class)) {
                continue;
            }

            $returnType = $method->getReturnType();

            if (! $returnType instanceof \ReflectionNamedType || ! is_subclass_of($returnType->getName(), Relation::class)) {
                continue;
            }

            try {
                /** @var Relation $relation */
                $relation = $model->{$method->getName()}();
                $related = $relation->getRelated();
                $relatedColumns = $this->columns($related);
            } catch (\Throwable) {
                continue;
            }

            $nameColumn = null;

            foreach (self::NAME_COLUMNS as $candidate) {
                if (in_array($candidate, $relatedColumns, true) && ! in_array($candidate, $related->getHidden(), true)) {
                    $nameColumn = $candidate;
                    break;
                }
            }

            $relations[$method->getName()] = new RelationSchema(
                name: $method->getName(),
                relatedModel: $related::class,
                nameColumn: $nameColumn,
            );

            if ($nameColumn !== null) {
                $path = $method->getName().'.'.$nameColumn;

                $fields[$path] = new FieldSchema(
                    name: $path,
                    kind: 'text',
                    aliases: [$method->getName()],
                    relation: $method->getName(),
                    relationColumn: $nameColumn,
                );
            }
        }

        // Config relation allow-list.
        if (($allow = $overrides['relations'] ?? null) !== null) {
            $allow = array_flip($allow);
            $relations = array_filter($relations, fn (RelationSchema $r) => isset($allow[$r->name]));
            $fields = array_filter($fields, fn (FieldSchema $f) => $f->relation === null || isset($allow[$f->relation]));
        }

        return [$fields, $relations];
    }

    /**
     * @param  array<string, FieldSchema>  $fields
     * @return array<string, FieldSchema>
     */
    private function applyOverrides(array $fields, array $overrides): array
    {
        if (($only = $overrides['only'] ?? null) !== null) {
            $fields = array_intersect_key($fields, array_flip($only));
        }

        foreach ($overrides['exclude'] ?? [] as $excluded) {
            unset($fields[$excluded]);
        }

        foreach ($overrides['fields'] ?? [] as $name => $settings) {
            if (! isset($fields[$name])) {
                continue;
            }

            $existing = $fields[$name];

            $fields[$name] = new FieldSchema(
                name: $name,
                kind: $existing->kind,
                label: $settings['label'] ?? $existing->label,
                aliases: [...$existing->aliases, ...($settings['aliases'] ?? [])],
                values: $settings['values'] ?? $existing->values,
                valueLabels: $settings['value_labels'] ?? $existing->valueLabels,
                unit: $settings['unit'] ?? $existing->unit,
                scale: isset($settings['scale']) ? (float) $settings['scale'] : $existing->scale,
                searchable: $existing->searchable || (bool) ($settings['searchable'] ?? false),
                orderable: $existing->orderable || (bool) ($settings['orderable'] ?? false),
                primary: $existing->primary,
                relation: $existing->relation,
                relationColumn: $existing->relationColumn,
                valueSource: $existing->valueSource,
                conditions: $existing->conditions,
                synonyms: [...$existing->synonyms, ...($settings['synonyms'] ?? [])],
            );
        }

        return $fields;
    }

    /** @return array<int, string> */
    private function resolveSearchable(array $fields, array $overrides): array
    {
        if (($search = $overrides['search'] ?? null) !== null) {
            return array_values(array_filter($search, fn ($f) => isset($fields[$f]) && $fields[$f]->kind === 'text' && $fields[$f]->relation === null));
        }

        $explicit = array_keys(array_filter($fields, fn (FieldSchema $f) => $f->searchable && $f->kind === 'text'));

        // Relation names alone are not a keyword search; they join one.
        if ($explicit !== [] && array_filter($explicit, fn (string $name) => $fields[$name]->relation === null) === []) {
            foreach ($fields as $field) {
                if ($field->kind === 'text' && $field->relation === null && in_array($field->name, self::SEARCHABLE_HINTS, true)) {
                    array_unshift($explicit, $field->name);
                }
            }
        }

        if ($explicit !== []) {
            return $explicit;
        }

        $searchable = [];

        foreach ($fields as $field) {
            if ($field->kind === 'text' && in_array($field->name, self::SEARCHABLE_HINTS, true)) {
                $searchable[] = $field->name;
            }
        }

        if ($searchable === []) {
            $text = array_values(array_filter($fields, fn (FieldSchema $f) => $f->kind === 'text' && ! $f->relation));
            if ($text !== []) {
                $searchable = [$text[0]->name];
            }
        }

        // A bare name ("hopper") can be a title word or a related name, so
        // the keyword search reads both. Three relations at most.
        if ($searchable !== []) {
            $names = array_filter($fields, fn (FieldSchema $f) => $f->kind === 'text' && $f->relation !== null && $f->relationColumn !== null);
            $searchable = [...$searchable, ...array_slice(array_keys($names), 0, 3)];
        }

        return $searchable;
    }

    /** @return array<int, string> */
    private function resolveOrderable(array $fields, array $overrides): array
    {
        if (($orderable = $overrides['orderable'] ?? null) !== null) {
            return array_values(array_filter($orderable, fn ($f) => isset($fields[$f]) && $fields[$f]->relation === null));
        }

        $orderable = array_keys(array_filter($fields, fn (FieldSchema $f) => $f->orderable));

        foreach ($fields as $field) {
            if ($field->isDate() && ! in_array($field->name, $orderable, true)) {
                $orderable[] = $field->name;
            }
        }

        return $orderable;
    }
}
