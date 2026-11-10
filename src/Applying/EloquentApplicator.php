<?php

namespace Meirdick\SearchChips\Applying;

use Illuminate\Database\Eloquent\Builder;
use Meirdick\SearchChips\Data\FieldSchema;
use Meirdick\SearchChips\Data\Filter;
use Meirdick\SearchChips\Data\FilterCollection;
use Meirdick\SearchChips\Data\ModelSchema;
use Meirdick\SearchChips\Data\Operator;
use Meirdick\SearchChips\Data\Stopwords;

/**
 * Applies validated filters to an Eloquent query. Relation paths
 * ("author.name") become nested whereHas; the leftover text becomes an
 * OR'd LIKE across the schema's searchable fields.
 */
final class EloquentApplicator
{
    private const MAX_RELATION_DEPTH = 2;

    public function apply(
        Builder $query,
        FilterCollection $filters,
        ModelSchema $schema,
        string $leftover = '',
        ?array $sort = null,
    ): Builder {
        foreach ($filters as $filter) {
            if ($filter->suggested) {
                continue;
            }
            $this->applyFilter($query, $filter, $schema);
        }

        if (trim($leftover) !== '' && $schema->searchable !== []) {
            $this->applyLeftover($query, trim($leftover), $schema);
        }

        if ($sort !== null && ($field = $schema->field($sort['field'])) !== null) {
            $query->orderBy($query->qualifyColumn($field->name), $sort['direction'] === 'asc' ? 'asc' : 'desc');
        }

        return $query;
    }

    public function applyFilter(Builder $query, Filter $filter, ModelSchema $schema): Builder
    {
        $field = $schema->field($filter->field);

        if ($field === null) {
            return $query;
        }

        if ($field->conditions !== []) {
            return $this->applyConditionalFilter($query, $filter, $field, $schema);
        }

        return $this->applyUnconditionalFilter($query, $filter, $field, $schema);
    }

    /**
     * A filter with row conditions only selects rows that meet them. Its
     * negation keeps every row that fails them, so a hidden value cannot be
     * read from what the negated filter leaves out.
     */
    private function applyConditionalFilter(Builder $query, Filter $filter, FieldSchema $field, ModelSchema $schema): Builder
    {
        $conditions = [];

        foreach ($field->conditions as $column => $value) {
            $conditions[$query->qualifyColumn($column)] = $value;
        }

        if (! $filter->negated) {
            $query->where($conditions);

            return $this->applyUnconditionalFilter($query, $filter, $field, $schema);
        }

        return $query->where(function (Builder $q) use ($conditions, $filter, $field, $schema) {
            $q->whereNot(fn (Builder $inner) => $inner->where($conditions))
                ->orWhere(fn (Builder $inner) => $this->applyUnconditionalFilter($inner, $filter, $field, $schema));
        });
    }

    private function applyUnconditionalFilter(Builder $query, Filter $filter, FieldSchema $field, ModelSchema $schema): Builder
    {
        if ($field->relation !== null && $field->relationColumn !== null) {
            return $this->applyRelationFilter($query, $filter, $field, $schema);
        }

        $apply = function (Builder $q) use ($filter, $field): Builder {
            $this->constraintFor($q, $filter, $field);

            return $q;
        };

        return $filter->negated
            ? $query->whereNot($apply)
            : $apply($query);
    }

    private function applyRelationFilter(Builder $query, Filter $filter, FieldSchema $field, ModelSchema $schema, int $depth = 0): Builder
    {
        if ($depth > self::MAX_RELATION_DEPTH) {
            return $query;
        }

        $method = $filter->negated ? 'whereDoesntHave' : 'whereHas';

        return $query->{$method}(
            $field->relation,
            fn (Builder $q) => $this->constraintFor($q, $filter, $field, useColumn: $field->relationColumn),
        );
    }

    private function constraintFor(Builder $query, Filter $filter, FieldSchema $field, ?string $useColumn = null): void
    {
        $column = $query->qualifyColumn($useColumn ?? $field->name);

        $this->{$this->methodFor($filter->operator)}($query, $column, $filter, $field);
    }

    private function methodFor(string $operator): string
    {
        return match ($operator) {
            Operator::EQUALS => 'equals',
            Operator::NOT_EQUALS => 'notEquals',
            Operator::CONTAINS => 'contains',
            Operator::STARTS_WITH => 'startsWith',
            Operator::ENDS_WITH => 'endsWith',
            Operator::IN => 'inList',
            Operator::GT, Operator::GTE, Operator::LT, Operator::LTE => 'compare',
            Operator::BETWEEN => 'between',
            Operator::BEFORE => 'before',
            Operator::AFTER => 'after',
            Operator::ON => 'on',
            Operator::IS_TRUE, Operator::IS_FALSE => 'boolean',
            Operator::IS_EMPTY => 'empty',
            Operator::NOT_EMPTY => 'notEmpty',
            default => 'equals',
        };
    }

    private function equals(Builder $q, string $column, Filter $filter, FieldSchema $field): void
    {
        $field->isDate() ? $q->whereDate($column, $filter->value) : $q->where($column, '=', $filter->value);
    }

    private function notEquals(Builder $q, string $column, Filter $filter, FieldSchema $field): void
    {
        $field->isDate() ? $q->whereDate($column, '!=', $filter->value) : $q->where($column, '!=', $filter->value);
    }

    private function contains(Builder $q, string $column, Filter $filter): void
    {
        $this->like($q, $column, '%'.$this->sanitizeLike((string) $filter->value).'%');
    }

    private function startsWith(Builder $q, string $column, Filter $filter): void
    {
        $this->like($q, $column, $this->sanitizeLike((string) $filter->value).'%');
    }

    private function endsWith(Builder $q, string $column, Filter $filter): void
    {
        $this->like($q, $column, '%'.$this->sanitizeLike((string) $filter->value));
    }

    /** One explicit escape character works on SQLite, MySQL, PostgreSQL and SQL Server. */
    private function like(Builder $q, string $column, string $pattern, string $boolean = 'and'): void
    {
        $wrapped = $q->getGrammar()->wrap($q->qualifyColumn($column));

        // PostgreSQL LIKE is case-sensitive, so it needs ILIKE. MySQL, SQLite
        // and SQL Server compare case-insensitively with their default
        // collations, and a bare column keeps a prefix search on its index.
        $sql = $q->getModel()->getConnection()->getDriverName() === 'pgsql'
            ? "{$wrapped}::text ilike ? escape '!'"
            : "{$wrapped} like ? escape '!'";

        $q->whereRaw($sql, [$pattern], $boolean);
    }

    private function inList(Builder $q, string $column, Filter $filter): void
    {
        $q->whereIn($column, (array) $filter->value);
    }

    private function compare(Builder $q, string $column, Filter $filter): void
    {
        $q->where($column, $this->symbolFor($filter->operator), $filter->value);
    }

    private function between(Builder $q, string $column, Filter $filter): void
    {
        $q->whereBetween($column, $filter->value);
    }

    private function before(Builder $q, string $column, Filter $filter): void
    {
        $q->where($column, '<', $filter->value);
    }

    private function after(Builder $q, string $column, Filter $filter): void
    {
        $q->where($column, '>', $filter->value);
    }

    private function on(Builder $q, string $column, Filter $filter): void
    {
        $q->whereDate($column, $filter->value);
    }

    private function boolean(Builder $q, string $column, Filter $filter): void
    {
        $q->where($column, $filter->operator === Operator::IS_TRUE);
    }

    private function empty(Builder $q, string $column): void
    {
        $q->whereNull($column);
    }

    private function notEmpty(Builder $q, string $column): void
    {
        $q->whereNotNull($column);
    }

    private function symbolFor(string $operator): string
    {
        return match ($operator) {
            Operator::GT => '>',
            Operator::GTE => '>=',
            Operator::LT => '<',
            Operator::LTE => '<=',
            default => '=',
        };
    }

    /** Leftover words stay a keyword search across the searchable fields. */
    private function applyLeftover(Builder $query, string $leftover, ModelSchema $schema): void
    {
        // Each word must appear somewhere; requiring the whole phrase in one
        // field would miss "rockets" inside "Rockets explained". Glue words
        // ("about", "with") carry no keyword, so they never constrain.
        foreach ($this->keywords($leftover) as $word) {
            $word = $this->sanitizeLike($word);

            if ($word === '') {
                continue;
            }

            $needle = '%'.$word.'%';

            $query->where(function (Builder $q) use ($needle, $schema) {
                foreach ($schema->searchable as $name) {
                    $field = $schema->field($name);

                    if ($field?->relation === null || $field->relationColumn === null) {
                        $this->like($q, $name, $needle, 'or');

                        continue;
                    }

                    // A related name ("hopper"), under the field's row conditions.
                    $q->orWhere(function (Builder $inner) use ($field, $needle) {
                        foreach ($field->conditions as $column => $value) {
                            $inner->where($inner->qualifyColumn($column), $value);
                        }

                        $inner->whereHas($field->relation, fn (Builder $related) => $this->like($related, $field->relationColumn, $needle));
                    });
                }
            });
        }
    }

    /**
     * Leftover words worth searching for. Stopwords are connectives the
     * parser left behind; searching for them would match nothing useful.
     *
     * @return array<int, string>
     */
    private function keywords(string $leftover): array
    {
        $stopwords = Stopwords::all();

        return array_values(array_filter(
            preg_split('/\s+/u', mb_strtolower(trim($leftover))) ?: [],
            fn ($word) => $word !== '' && ! in_array($this->sanitizeLike($word), $stopwords, true),
        ));
    }

    /** Escape metacharacters without changing the user's literal search text. */
    private function sanitizeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
