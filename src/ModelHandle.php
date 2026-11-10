<?php

namespace Meirdick\SearchChips;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Meirdick\SearchChips\Data\ModelSchema;
use Meirdick\SearchChips\Registry\RegisteredModel;

/**
 * The fluent per-model handle returned by SearchChips::for(Post::class).
 */
final class ModelHandle
{
    public function __construct(
        private readonly SearchChipsManager $manager,
        private readonly RegisteredModel $model,
    ) {}

    public function alias(): string
    {
        return $this->model->alias;
    }

    /** @return class-string<Model> */
    public function modelClass(): string
    {
        return $this->model->modelClass;
    }

    public function schema(): ModelSchema
    {
        return $this->manager->schema($this->model->alias);
    }

    public function parse(string $query, bool $refine = true): ParseResult
    {
        return $this->manager->parse($query, $this->model->alias, $refine);
    }

    public function search(string $query, bool $refine = true): PendingSearch
    {
        return $this->manager->search($query, $this->model->alias, $refine);
    }

    /**
     * @param  array<int, array<string, mixed>>  $chips  chip arrays as returned by parse
     */
    public function applyChips(array $chips, string $leftover = '', ?array $sort = null, ?string $query = null): PendingSearch
    {
        return $this->manager->applyChips($this->model->alias, $chips, $leftover, $sort, $query);
    }

    /**
     * Build the search from a page request. Reads the chip set the UI
     * component sends (`chips`, `leftover`, `sort`), or parses the `q`
     * text when no chips came with it. With neither, nothing is filtered.
     */
    public function fromRequest(Request $request): PendingSearch
    {
        $chips = $request->input('chips');

        if (is_string($chips)) {
            $chips = json_decode($chips, true);
        }

        if (is_array($chips)) {
            $sort = $request->input('sort');
            $sort = is_string($sort) ? json_decode($sort, true) : $sort;

            return $this->applyChips(
                array_slice(array_values($chips), 0, (int) config('search-chips.limits.max_filters', 10)),
                (string) $request->input('leftover', ''),
                is_array($sort) ? $sort : null,
                $request->filled('q') ? (string) $request->input('q') : null,
            );
        }

        return $this->search((string) $request->input('q', ''));
    }
}
