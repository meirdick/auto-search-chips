<?php

namespace Meirdick\SearchChips\Applying;

use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\CollectionEngine;
use Laravel\Scout\Engines\DatabaseEngine;
use Laravel\Scout\Engines\MeilisearchEngine;
use Laravel\Scout\Engines\TypesenseEngine;
use Meirdick\SearchChips\Data\FieldSchema;
use Meirdick\SearchChips\Data\Filter;
use Meirdick\SearchChips\Data\FilterCollection;
use Meirdick\SearchChips\Data\ModelSchema;
use Meirdick\SearchChips\Data\Operator;

/**
 * Sends chips to a Laravel Scout engine as filters, so the engine pages and
 * counts the filtered result.
 *
 * The collection and database engines run Scout's search as SQL, so every
 * chip goes there through the EloquentApplicator. Meilisearch and Typesense
 * get Scout's where(), whereIn() and whereNotIn() for equality and lists,
 * and their own filter syntax for ranges. A chip one of them cannot apply
 * (relation fields, onlyWhen() fields, text matching, fields missing from
 * the index settings) is the caller's to filter in the database.
 */
final class ScoutApplicator
{
    /** Operators an index applies. Text matching and empty checks stay in SQL. */
    private const INDEX_OPERATORS = [
        Operator::EQUALS, Operator::NOT_EQUALS, Operator::IN, Operator::IS_TRUE, Operator::IS_FALSE,
        Operator::GT, Operator::GTE, Operator::LT, Operator::LTE, Operator::BETWEEN, Operator::BEFORE, Operator::AFTER,
    ];

    public function __construct(private readonly EloquentApplicator $eloquent) {}

    /** collection, database, meilisearch or typesense; null for an engine chips cannot reach (Algolia, custom engines). */
    public function engine(Model $model): ?string
    {
        $engine = method_exists($model, 'searchableUsing') ? $model->searchableUsing() : null;

        return match (true) {
            $engine instanceof CollectionEngine => 'collection',
            $engine instanceof DatabaseEngine => 'database',
            $engine instanceof MeilisearchEngine => 'meilisearch',
            $engine instanceof TypesenseEngine => 'typesense',
            default => null,
        };
    }

    /** Whether a search index can hold the field: a column of the model, readable on every row. */
    public static function indexable(FieldSchema $field): bool
    {
        return $field->relation === null && $field->conditions === [];
    }

    public function supports(Model $model, Filter $filter, ModelSchema $schema): bool
    {
        $engine = $this->engine($model);

        if ($engine === 'collection' || $engine === 'database') {
            return true;
        }

        $field = $schema->field($filter->field);

        if ($engine === null || $field === null || ! self::indexable($field) || ! in_array($filter->operator, self::INDEX_OPERATORS, true)) {
            return false;
        }

        if ($engine === 'meilisearch') {
            return in_array($field->name, $this->meilisearchSettings($model)['filterableAttributes'] ?? [], true)
                && (! $field->isDate() || $this->indexesTimestamp($model, $field->name));
        }

        $index = $this->typesenseField($model, $field->name);
        $values = $this->values($filter);

        if ($index === null || ($index['index'] ?? true) === false) {
            return false;
        }

        // Typesense has no "is not null" filter: a negation on an optional
        // field would return the rows where the field is empty.
        if ($this->isNegative($filter) && ($index['optional'] ?? false)) {
            return false;
        }

        return match ($index['type'] ?? null) {
            'string' => array_filter($values, fn ($v) => ! is_string($v) || str_contains($v, '`')) === [],
            'int32', 'int64' => array_filter($values, fn ($v) => ! is_int($v)) === [],
            'float' => array_filter($values, fn ($v) => ! is_int($v) && ! is_float($v)) === [],
            'bool' => in_array($filter->operator, [Operator::IS_TRUE, Operator::IS_FALSE], true),
            default => false,
        };
    }

    /** @param  array{field: string, direction: string}  $sort */
    public function supportsSort(Model $model, array $sort, ModelSchema $schema): bool
    {
        $field = $schema->field($sort['field']);

        if ($field === null) {
            return true; // the database path ignores it too
        }

        $engine = $this->engine($model);
        $index = $engine === 'typesense' ? $this->typesenseField($model, $field->name) : null;

        return match ($engine) {
            'collection', 'database' => true,
            'meilisearch' => in_array($field->name, $this->meilisearchSettings($model)['sortableAttributes'] ?? [], true),
            // Typesense sorts numbers by default and strings with 'sort' => true.
            'typesense' => $index !== null && (bool) ($index['sort'] ?? in_array($index['type'] ?? null, ['int32', 'int64', 'float', 'bool'], true)),
            default => false,
        };
    }

    /**
     * Add chips and a sort to a search. Pass only chips the engine supports.
     *
     * @param  (Closure(EloquentBuilder): mixed)|null  $constrain  more SQL for the collection and database engines
     */
    public function apply(Builder $search, FilterCollection $filters, ModelSchema $schema, ?array $sort = null, ?Closure $constrain = null): Builder
    {
        $model = $search->model;
        $engine = $this->engine($model);
        $field = $sort === null ? null : $schema->field($sort['field']);

        if ($field !== null) {
            $search->orderBy($engine === 'collection' || $engine === 'database' ? $model->qualifyColumn($field->name) : $field->name, $sort['direction'] === 'asc' ? 'asc' : 'desc');
        }

        if ($engine === 'collection' || $engine === 'database') {
            // These engines skip where() clauses when a callback is set and
            // pass the callback their Eloquent query instead.
            $search->callback = function (EloquentBuilder $query) use ($filters, $schema, $constrain) {
                if ($constrain !== null) {
                    $constrain($query);
                }

                $this->eloquent->apply($query, $filters, $schema);
            };

            return $search;
        }

        $native = [];

        foreach ($filters as $filter) {
            if ($filter->suggested) {
                continue;
            }

            if (! $this->addScoutClause($search, $filter)) {
                $native[] = $engine === 'meilisearch' ? $this->meilisearchFilter($filter) : $this->typesenseFilter($filter);
            } elseif ($engine === 'meilisearch' && $this->isNegative($filter)) {
                // SQL leaves rows with an empty field out of "is not"; Meilisearch keeps them.
                $native[] = "({$filter->field} EXISTS AND {$filter->field} IS NOT NULL)";
            }
        }

        if ($native === []) {
            return $search;
        }

        $search->callback = $engine === 'meilisearch'
            ? function ($index, ?string $query, array $options) use ($native) {
                $options['filter'] = implode(' AND ', array_filter([$options['filter'] ?? '', ...$native]));

                return $index->rawSearch($query, $options);
            }
        : function ($documents, ?string $query, array $options) use ($native) {
            $options['filter_by'] = implode(' && ', array_filter([$options['filter_by'] ?? '', ...$native]));

            return $documents->search($options);
        };

        return $search;
    }

    /**
     * Equality and lists go through Scout's own clauses. Scout keys
     * whereIn() and whereNotIn() by field (and where() too in Scout 10), so a
     * second chip on a taken field uses the engine syntax instead.
     */
    private function addScoutClause(Builder $search, Filter $filter): bool
    {
        $negative = $this->isNegative($filter);
        $values = $this->values($filter);

        [$method, $value] = match ($filter->operator) {
            Operator::EQUALS, Operator::NOT_EQUALS => $negative ? ['whereNotIn', $values] : ['where', $values[0]],
            Operator::IN => [$negative ? 'whereNotIn' : 'whereIn', $values],
            Operator::IS_TRUE, Operator::IS_FALSE => ['where', ($filter->operator === Operator::IS_TRUE) !== $filter->negated],
            default => [null, null],
        };

        $taken = match ($method) {
            'where' => $search->wheres,
            'whereIn' => $search->whereIns,
            'whereNotIn' => $search->whereNotIns,
            default => null,
        };

        if ($taken === null || array_key_exists($filter->field, $taken)) {
            return false;
        }

        $search->{$method}($filter->field, $value);

        return true;
    }

    /**
     * Meilisearch filter syntax. SQL leaves rows with an empty field out of
     * a negated comparison; Meilisearch keeps them unless told otherwise.
     */
    private function meilisearchFilter(Filter $filter): string
    {
        $field = $filter->field;
        $values = array_map(fn ($value) => match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => $this->number($value),
            default => '"'.addcslashes((string) $value, '"\\').'"',
        }, $this->values($filter));

        $clause = match ($filter->operator) {
            Operator::EQUALS => "{$field} = {$values[0]}",
            Operator::NOT_EQUALS => "{$field} != {$values[0]}",
            Operator::IN => "{$field} IN [".implode(', ', $values).']',
            Operator::IS_TRUE => "{$field} = true",
            Operator::IS_FALSE => "{$field} = false",
            Operator::GT, Operator::AFTER => "{$field} > {$values[0]}",
            Operator::GTE => "{$field} >= {$values[0]}",
            Operator::LT, Operator::BEFORE => "{$field} < {$values[0]}",
            Operator::LTE => "{$field} <= {$values[0]}",
            Operator::BETWEEN => "{$field} {$values[0]} TO {$values[1]}",
            default => throw new InvalidArgumentException("[{$filter->operator}] is not an index filter."),
        };

        if ($filter->negated) {
            $clause = "NOT ({$clause})";
        }

        return $this->isNegative($filter)
            ? "({$field} EXISTS AND {$field} IS NOT NULL AND {$clause})"
            : "({$clause})";
    }

    /** Typesense filter syntax. supports() keeps negations to required fields. */
    private function typesenseFilter(Filter $filter): string
    {
        $field = $filter->field;
        $values = array_map(fn ($value) => match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => $this->number($value),
            default => '`'.$value.'`',
        }, $this->values($filter));

        $negated = $filter->negated;

        return match ($filter->operator) {
            Operator::EQUALS => $field.($negated ? ':!=' : ':=').$values[0],
            Operator::NOT_EQUALS => $field.($negated ? ':=' : ':!=').$values[0],
            Operator::IN => $field.($negated ? ':!=' : ':=').'['.implode(', ', $values).']',
            Operator::IS_TRUE => $field.':='.($negated ? 'false' : 'true'),
            Operator::IS_FALSE => $field.':='.($negated ? 'true' : 'false'),
            Operator::GT, Operator::AFTER => $field.($negated ? ':<=' : ':>').$values[0],
            Operator::GTE => $field.($negated ? ':<' : ':>=').$values[0],
            Operator::LT, Operator::BEFORE => $field.($negated ? ':>=' : ':<').$values[0],
            Operator::LTE => $field.($negated ? ':>' : ':<=').$values[0],
            Operator::BETWEEN => $negated
                ? "({$field}:<{$values[0]} || {$field}:>{$values[1]})"
                : "{$field}:[{$values[0]}..{$values[1]}]",
            default => throw new InvalidArgumentException("[{$filter->operator}] is not an index filter."),
        };
    }

    /** Whether the chip excludes values ("is not", "not one of", "not above"). */
    private function isNegative(Filter $filter): bool
    {
        return $filter->negated !== ($filter->operator === Operator::NOT_EQUALS);
    }

    /**
     * The chip's values as the index stores them: dates as Unix
     * timestamps, whole numbers as integers.
     *
     * @return array<int, mixed>
     */
    private function values(Filter $filter): array
    {
        return array_map(fn ($value) => match (true) {
            $value instanceof DateTimeInterface => $value->getTimestamp(),
            $value instanceof BackedEnum => $value->value,
            is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX => (int) $value,
            default => $value,
        }, match (true) {
            $filter->value === null => [],
            is_array($filter->value) => array_values($filter->value),
            default => [$filter->value],
        });
    }

    private function number(int|float $value): string
    {
        return is_int($value) ? (string) $value : rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
    }

    /**
     * Whether toSearchableArray() stores the date as a Unix timestamp.
     * Meilisearch has no field types: a range filter on a date string
     * matches no row and gives no error, so such a chip stays in the database.
     */
    private function indexesTimestamp(Model $model, string $name): bool
    {
        static $known = [];

        return $known[$model::class.'.'.$name] ??= (function () use ($model, $name) {
            try {
                $probe = $model->newInstance()->forceFill([$name => '2000-01-01 00:00:00']);

                return is_int(call_user_func([$probe, 'toSearchableArray'])[$name] ?? null);
            } catch (\Throwable) {
                return false;
            }
        })();
    }

    /** The model's entry in config('scout.meilisearch.index-settings'), by class or index name. */
    private function meilisearchSettings(Model $model): array
    {
        $prefix = (string) config('scout.prefix', '');

        foreach ((array) config('scout.meilisearch.index-settings', []) as $name => $settings) {
            if (is_array($settings) && ($name === $model::class || (method_exists($model, 'searchableAs') && Str::start((string) $name, $prefix) === $model->searchableAs()))) {
                return $settings;
            }
        }

        return [];
    }

    /** The field in the model's Typesense collection schema. */
    private function typesenseField(Model $model, string $name): ?array
    {
        $schema = method_exists($model, 'typesenseCollectionSchema')
            ? $model->typesenseCollectionSchema()
            : config('scout.typesense.model-settings.'.$model::class.'.collection-schema', []);

        foreach ($schema['fields'] ?? [] as $field) {
            if (($field['name'] ?? null) === $name) {
                return $field;
            }
        }

        return null;
    }
}
