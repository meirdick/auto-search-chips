<?php

namespace Meirdick\SearchChips\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Meirdick\SearchChips\PendingSearch;
use Meirdick\SearchChips\SearchChipsManager;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SearchChipsController
{
    public function __construct(private readonly SearchChipsManager $manager) {}

    /** Query text in, chips out. `refine: false` asks for the instant rules reading only. */
    public function parse(Request $request, string $alias): JsonResponse
    {
        $this->manager->authorize($alias);

        $validated = $request->validate([
            'query' => ['required', 'string', 'max:'.config('search-chips.limits.max_query_length', 300)],
            'refine' => ['nullable', 'boolean'],
            'with' => ['nullable', 'string', 'in:results'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $pending = $this->manager->search($validated['query'], $alias, (bool) ($validated['refine'] ?? true));

        return response()->json($this->payload($pending, $request));
    }

    /** An edited chip set in, validated chips and the new count out. No engine runs. */
    public function apply(Request $request, string $alias): JsonResponse
    {
        $this->manager->authorize($alias);

        $validated = $request->validate([
            'chips' => ['present', 'array', 'max:'.config('search-chips.limits.max_filters', 10)],
            'chips.*.field' => ['required', 'string', 'max:120'],
            'chips.*.operator' => ['required', 'string', 'max:40'],
            'chips.*.value' => ['nullable'],
            'chips.*.negated' => ['nullable', 'boolean'],
            'chips.*.suggested' => ['nullable', 'boolean'],
            'chips.*.id' => ['nullable', 'string', 'max:80'],
            'leftover' => ['nullable', 'string', 'max:'.config('search-chips.limits.max_query_length', 300)],
            'sort' => ['nullable', 'array'],
            'with' => ['nullable', 'string', 'in:results'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $pending = $this->manager->applyChips(
            $alias,
            array_values($request->input('chips', [])),
            (string) ($validated['leftover'] ?? ''),
            $validated['sort'] ?? null,
        );

        return response()->json($this->payload($pending, $request));
    }

    public function script(): BinaryFileResponse
    {
        return response()->file(__DIR__.'/../../dist/search-chips.js', [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    private function payload(PendingSearch $pending, Request $request): array
    {
        $payload = $pending->toArray();

        if ($request->input('with') === 'results') {
            $payload['results'] = $this->results($pending, (int) $request->input('limit', 25));
        }

        return $payload;
    }

    /** @return array<int, mixed> */
    private function results(PendingSearch $pending, int $limit): array
    {
        $models = $pending->query()->limit($limit)->get();
        $options = $pending->model->options;

        if (is_string($resource = $options['resource'] ?? null) && is_subclass_of($resource, JsonResource::class)) {
            return $resource::collection($models)->resolve();
        }

        $columns = $options['columns']
            ?? array_values(array_unique([$models->first()?->getKeyName() ?? 'id', ...$pending->schema->searchable]));

        return $models->map(fn ($model) => $model->only($columns))->all();
    }
}
