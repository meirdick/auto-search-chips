<?php

namespace Meirdick\SearchChips;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Laravel\Scout\Builder as ScoutBuilder;
use Meirdick\SearchChips\Applying\EloquentApplicator;
use Meirdick\SearchChips\Applying\ScoutApplicator;
use Meirdick\SearchChips\Data\FilterCollection;
use Meirdick\SearchChips\Data\ModelSchema;
use Meirdick\SearchChips\Events\SearchChipsApplied;
use Meirdick\SearchChips\Registry\RegisteredModel;

/**
 * A parsed query bound to a model, ready to execute. The fluent terminal:
 *
 *   SearchChips::for('posts')->search('videos by Ada above 10k views')->paginate();
 *
 * ->query() hands back a fresh, fully filtered builder for custom work.
 */
final class PendingSearch implements Arrayable
{
    private bool $appliedEvent = false;

    public function __construct(
        public readonly string $query,
        public readonly ParseResult $result,
        public readonly ModelSchema $schema,
        public readonly RegisteredModel $model,
        private readonly EloquentApplicator $applicator,
        /** @var \Closure(): Builder */
        private readonly \Closure $baseQuery,
    ) {}

    public function filters(): FilterCollection
    {
        return $this->result->filters;
    }

    public function chips(): array
    {
        return $this->result->filters->toArray();
    }

    public function leftover(): string
    {
        return $this->result->leftover;
    }

    public function sort(): ?array
    {
        return $this->result->sort;
    }

    public function engine(): string
    {
        return $this->result->engine;
    }

    /**
     * A fresh builder with all filters applied — for custom scopes, joins,
     * selects, or anything else the developer wants to add.
     */
    public function query(): Builder
    {
        $query = ($this->baseQuery)();
        $leftover = $this->result->leftover;

        if ($leftover !== '' && $this->usesScout()) {
            // Scout finds the rows for the keyword text, narrowed by the
            // chips its engine can apply. Every chip then filters those rows
            // in the database, on any Scout engine.
            $pushable = config('search-chips.scout.filters', true)
                ? $this->filters()->filter(fn ($filter) => $this->scout()->supports($query->getModel(), $filter, $this->schema))
                : new FilterCollection([]);

            $keys = $this->scout()->apply($this->scoutSearch(), $pushable, $this->schema)
                ->take((int) config('search-chips.scout.limit', 1000))
                ->keys();

            $query->whereKey($keys->all());
            $leftover = '';

            if ($this->result->sort === null && $keys->isNotEmpty() && $keys->count() <= 200) {
                $this->orderByRelevance($query, $keys->all());
            }
        }

        return $this->applicator->apply($query, $this->result->filters, $this->schema, $leftover, $this->result->sort);
    }

    /** Whether Laravel Scout runs the keyword part of this search. */
    public function usesScout(): bool
    {
        return config('search-chips.scout.enabled', true)
            && in_array('Laravel\\Scout\\Searchable', class_uses_recursive($this->model->modelClass), true);
    }

    /**
     * Whether the Scout engine applies every chip and the sort, so get(),
     * paginate() and count() take their rows and totals from it. Otherwise
     * they filter Scout's matching keys in the database, like query().
     */
    public function usesScoutFilters(): bool
    {
        if ($this->result->leftover === '' || ! $this->usesScout() || ! config('search-chips.scout.filters', true)) {
            return false;
        }

        $model = new $this->model->modelClass;
        $scout = $this->scout();

        // A base query scope is SQL: only the collection and database engines run it.
        if ($this->scope() !== null && ! in_array($scout->engine($model), ['collection', 'database'], true)) {
            return false;
        }

        foreach ($this->filters() as $filter) {
            if (! $filter->suggested && ! $scout->supports($model, $filter, $this->schema)) {
                return false;
            }
        }

        return $this->result->sort === null || $scout->supportsSort($model, $this->result->sort, $this->schema);
    }

    /** The search for the keyword text, with every chip and the sort applied by the engine. */
    private function engineSearch(): ScoutBuilder
    {
        $scope = $this->scope() === null ? null : function (Builder $query) {
            $key = $query->getModel()->getQualifiedKeyName();

            $query->whereIn($key, ($this->baseQuery)()->reorder()->select($key));
        };

        return $this->scout()->apply($this->scoutSearch(), $this->filters(), $this->schema, $this->result->sort, $scope);
    }

    private function scoutSearch(): ScoutBuilder
    {
        return $this->model->modelClass::search($this->result->leftover);
    }

    private function scout(): ScoutApplicator
    {
        return new ScoutApplicator($this->applicator);
    }

    /** The 'query' option of SearchChips::register(). */
    private function scope(): ?\Closure
    {
        $scope = $this->model->options['query'] ?? null;

        return $scope instanceof \Closure ? $scope : null;
    }

    /** Keep the order the search engine ranked the rows in. */
    private function orderByRelevance(Builder $query, array $keys): void
    {
        $column = $query->getGrammar()->wrap($query->getModel()->getQualifiedKeyName());
        $cases = '';
        $bindings = [];

        foreach (array_values($keys) as $position => $key) {
            $cases .= ' when ? then '.$position;
            $bindings[] = $key;
        }

        $query->orderByRaw("case {$column}{$cases} else ".count($keys).' end', $bindings);
    }

    public function count(): int
    {
        return $this->usesScoutFilters()
            ? $this->engineSearch()->paginateRaw(1, 'page', 1)->total()
            : $this->query()->count();
    }

    public function get(): Collection
    {
        $models = $this->usesScoutFilters()
            ? $this->engineSearch()->take((int) config('search-chips.scout.limit', 1000))->get()
            : $this->query()->get();

        $this->fireApplied(count($models));

        return $models;
    }

    public function paginate(int $perPage = 15, string $pageName = 'page', ?int $page = null): LengthAwarePaginator
    {
        if ($this->usesScoutFilters()) {
            $scout = $this->engineSearch()->paginate($perPage, $pageName, $page);

            // The same paginator as the database path: Scout's adds ?query= to the page links.
            $paginator = new LengthAwarePaginator(
                (new $this->model->modelClass)->newCollection($scout->items()),
                $scout->total(),
                $scout->perPage(),
                $scout->currentPage(),
                ['path' => Paginator::resolveCurrentPath(), 'pageName' => $pageName],
            );
        } else {
            $paginator = $this->query()->paginate($perPage, ['*'], $pageName, $page);
        }

        $this->fireApplied($paginator->total());

        return $paginator;
    }

    /** The chip payload plus the result count, as the JSON routes return it. */
    public function toArray(): array
    {
        return [
            ...$this->result->toArray(),
            'count' => $this->count(),
        ];
    }

    /**
     * Page props for the Inertia components: the chip payload plus the text
     * to show in the search box. Pass it as the component's `initial` prop.
     */
    public function toProps(): array
    {
        $payload = $this->toArray();

        return [
            'query' => $this->query,
            'chips' => $payload['filters'],
            'leftover' => $payload['leftover'],
            'sort' => $payload['sort'],
            'count' => $payload['count'],
            'engine' => $payload['engine'],
            'fields' => $this->schema->toPanel(),
        ];
    }

    private function fireApplied(int $count): void
    {
        if (! $this->appliedEvent) {
            $this->appliedEvent = true;

            SearchChipsApplied::dispatch($this->query, $this->model->alias, $this->result->filters, $count, $this->result->engine);
        }
    }
}
