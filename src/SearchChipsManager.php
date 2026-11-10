<?php

namespace Meirdick\SearchChips;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Meirdick\SearchChips\Applying\EloquentApplicator;
use Meirdick\SearchChips\Contracts\Parser;
use Meirdick\SearchChips\Data\FilterCollection;
use Meirdick\SearchChips\Data\ModelSchema;
use Meirdick\SearchChips\Data\ParsedQuery;
use Meirdick\SearchChips\Events\SearchChipsParsed;
use Meirdick\SearchChips\Exceptions\EngineUnavailableException;
use Meirdick\SearchChips\Exceptions\UnknownModelAliasException;
use Meirdick\SearchChips\Parsing\ChatLlmParser;
use Meirdick\SearchChips\Parsing\FilterNormalizer;
use Meirdick\SearchChips\Parsing\RulesParser;
use Meirdick\SearchChips\Parsing\SystemOneParser;
use Meirdick\SearchChips\Parsing\WorkersAiExtractor;
use Meirdick\SearchChips\Registry\ModelRegistry;
use Meirdick\SearchChips\Registry\RegisteredModel;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The public API behind the SearchChips facade:
 *
 *   SearchChips::for(Post::class)->search('videos by Ada above 10k views')->paginate();
 *
 * The deterministic rules engine always runs and its chips always stand.
 * An AI engine, when one is configured, adds the chips the rules missed.
 */
class SearchChipsManager
{
    /** @var array<string, Closure(Container): Parser> */
    protected array $engines = [];

    public function __construct(
        protected Container $app,
        protected ModelRegistry $registry,
        protected RulesParser $rules,
        protected EloquentApplicator $applicator,
    ) {}

    // ------------------------------------------------------------ Registry

    /**
     * Register a model under an alias. The alias is the only model handle
     * the HTTP routes and UI components accept.
     *
     * @param  class-string<Model>  $modelClass
     */
    public function register(string $modelClass, ?string $alias = null, array $options = []): RegisteredModel
    {
        return $this->registry->register($modelClass, $alias, $options);
    }

    /**
     * Register every model class in a directory (defaults to app/Models).
     *
     * @return array<int, string> aliases registered
     */
    public function discover(?string $directory = null, ?string $namespace = null): array
    {
        $directory ??= app_path('Models');
        $namespace ??= $this->app->getNamespace().'Models';

        return $this->registry->discover($directory, $namespace);
    }

    /**
     * The fluent handle for a model, by alias or by class. A class that
     * was never registered works for code-level use, and stays unreachable
     * over HTTP until it is registered.
     *
     * @param  string|class-string<Model>  $model
     */
    public function for(string $model): ModelHandle
    {
        return new ModelHandle($this, $this->resolve($model));
    }

    public function aliasFor(string $modelClass): ?string
    {
        return $this->registry->aliasFor($modelClass);
    }

    public function schema(string $model): ModelSchema
    {
        return $this->registry->schema($this->resolve($model));
    }

    /** @return array<string, RegisteredModel> */
    public function models(): array
    {
        return $this->registry->all();
    }

    public function resolve(string $model): RegisteredModel
    {
        if ($this->registry->has($model)) {
            return $this->registry->get($model);
        }

        if (class_exists($model) && is_subclass_of($model, Model::class)) {
            $alias = $this->registry->aliasFor($model);

            return $alias !== null
                ? $this->registry->get($alias)
                : $this->registry->register($model, exposed: false);
        }

        throw new UnknownModelAliasException("Unknown model alias [{$model}]. Register it with SearchChips::register() or in config('search-chips.models').");
    }

    // ------------------------------------------------------------- Engines

    /**
     * Add a custom engine. Select it with config('search-chips.engine') or
     * a model's 'engine' option.
     *
     * @param  Closure(Container): Parser  $factory
     */
    public function extend(string $name, Closure $factory): static
    {
        $this->engines[$name] = $factory;

        return $this;
    }

    /** Whether an AI engine can refine a rules-only parse of this model. */
    public function refinable(string $model): bool
    {
        return $this->engineFor($this->resolve($model)) !== null;
    }

    // ------------------------------------------------------------- Parsing

    /**
     * Parse a query into chips. With $refine false only the rules engine
     * runs: no network, a few milliseconds. The result then says whether
     * an AI engine can refine it.
     */
    public function parse(string $query, string $model, bool $refine = true): ParseResult
    {
        $registered = $this->resolve($model);
        $schema = $this->registry->schema($registered);
        $query = mb_substr(trim($query), 0, (int) config('search-chips.limits.max_query_length', 300));

        if ($query === '') {
            return new ParseResult(new FilterCollection([]), '', null, 'none');
        }

        $rules = $this->normalize($this->rules->parse($query, $schema), $query, $schema);
        $engine = $this->engineFor($registered);

        if ($engine === null) {
            $result = $rules;
        } elseif (! $refine) {
            $result = $rules->withRefinable(true);
        } else {
            $ai = $this->parseWithAi($engine, $query, $schema, $registered);

            $result = match (true) {
                $ai === null => $rules,
                $ai->filters->isEmpty() => $rules,
                $rules->filters->isEmpty() => $ai,
                default => $this->merge($ai, $rules, $query),
            };

            if ($ai !== null) {
                $result = $this->pruneLeftover($result, $ai);
            }
        }

        SearchChipsParsed::dispatch($query, $registered->alias, $result->filters, $result->engine, $result->leftover, $result->warnings);

        return $result;
    }

    /**
     * Re-apply a chip set the client edited (removed chips, clear all).
     * Chips are validated against the schema exactly like engine output.
     */
    public function applyChips(string $model, array $chips, string $leftover = '', ?array $sort = null, ?string $query = null): PendingSearch
    {
        $registered = $this->resolve($model);
        $schema = $this->registry->schema($registered);

        $filters = array_map(function ($chip) {
            if (! is_array($chip)) {
                return $chip;
            }

            $chip['source'] ??= 'user';
            $chip['explicit'] = true; // user-curated chips outrank inference

            return $chip;
        }, array_values($chips));

        $result = $this->normalize(
            new ParsedQuery(filters: $filters, leftover: $leftover, sort: $sort, engine: 'user'),
            $leftover,
            $schema,
        );

        return $this->pending($result, $registered, $query ?? $leftover);
    }

    public function search(string $query, string $model, bool $refine = true): PendingSearch
    {
        $registered = $this->resolve($model);

        return $this->pending($this->parse($query, $registered->alias, $refine), $registered, $query);
    }

    public function pending(ParseResult $result, RegisteredModel $registered, string $query = ''): PendingSearch
    {
        return new PendingSearch(
            $query,
            $result,
            $this->registry->schema($registered),
            $registered,
            $this->applicator,
            function () use ($registered) {
                $builder = $registered->modelClass::query();
                $scope = $registered->options['query'] ?? null;

                return $scope instanceof Closure ? ($scope($builder) ?? $builder) : $builder;
            },
        );
    }

    // -------------------------------------------------------- Authorization

    /**
     * Guard the HTTP routes and UI components: the alias must be registered
     * and exposed, and the global and per-model abilities must pass.
     */
    public function authorize(string $alias): RegisteredModel
    {
        $registered = $this->registry->has($alias) ? $this->registry->get($alias) : null;

        if ($registered === null || ! $registered->exposed) {
            throw new NotFoundHttpException("Unknown search alias [{$alias}].");
        }

        foreach ([config('search-chips.authorize'), $registered->options['authorize'] ?? null] as $rule) {
            if ($rule instanceof Closure) {
                Gate::allowIf((bool) $rule($this->app['request']->user(), $registered->modelClass));
            } elseif (is_string($rule) && $rule !== '') {
                Gate::authorize($rule, $registered->modelClass);
            }
        }

        return $registered;
    }

    // ---------------------------------------------------------- Engine work

    /** The AI engine for a model, or null when only rules apply. */
    protected function engineFor(RegisteredModel $registered): ?Parser
    {
        $name = (string) ($registered->options['engine'] ?? config('search-chips.engine', 'auto'));

        if ($name === 'rules') {
            return null;
        }

        // `auto` never calls a model from a test run. A test that wants an
        // engine sets it by name and fakes the HTTP call.
        if ($name === 'auto' && $this->app->runningUnitTests()) {
            return null;
        }

        // No automatic fallback between AI providers. `auto` selects System
        // One when it is configured, otherwise rules.
        $engine = $this->engine($name === 'auto' ? 'system-one' : $name);

        return $engine->available() ? $engine : null;
    }

    protected function engine(string $name): Parser
    {
        if (isset($this->engines[$name])) {
            return ($this->engines[$name])($this->app);
        }

        return match ($name) {
            'system-one' => new SystemOneParser(
                (array) config('search-chips.ai.cloudflare', []),
                $this->rules,
                $this->app->bound(WorkersAiExtractor::class) ? $this->app->make(WorkersAiExtractor::class) : null,
            ),
            'ai' => new ChatLlmParser((array) config('search-chips.ai.chat', [])),
            default => throw new InvalidArgumentException("Search chips engine [{$name}] is not defined. Use rules, auto, system-one, ai, or one added with SearchChips::extend()."),
        };
    }

    private function parseWithAi(Parser $engine, string $query, ModelSchema $schema, RegisteredModel $registered): ?ParseResult
    {
        $run = function () use ($engine, $query, $schema): ?ParseResult {
            try {
                $result = $this->normalize($engine->parse($query, $schema), $query, $schema);
            } catch (EngineUnavailableException $exception) {
                report($exception);

                return null; // the rules reading is always ready
            }

            return $result->filters->isNotEmpty() || $result->keywords !== null ? $result : null;
        };

        if (! config('search-chips.cache.enabled', true)) {
            return $run();
        }

        // Relative dates ("last week") resolve against today, so the day is
        // part of the key.
        $key = 'search-chips:parse:'.ModelRegistry::epoch().':'.md5(implode('|', [
            $registered->alias, $engine::class, $schema->version(), now()->toDateString(), mb_strtolower($query),
        ]));

        $cached = ModelRegistry::cache()->get($key);

        if ($cached instanceof ParseResult) {
            return $cached;
        }

        if (($result = $run()) !== null) {
            ModelRegistry::cache()->put($key, $result, (int) config('search-chips.cache.ttl', 300));
        }

        return $result;
    }

    /**
     * The AI engine names the words that are the topic of the search. A
     * leftover word outside that list is glue ("after", "then", "written")
     * and leaves the keyword search. The engine never adds a word.
     */
    private function pruneLeftover(ParseResult $result, ParseResult $ai): ParseResult
    {
        if ($result->leftover === '' || $ai->keywords === null || $ai->keywords === []) {
            return $result;
        }

        $topic = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(implode(' ', $ai->keywords)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $kept = array_filter(
            preg_split('/\s+/u', $result->leftover, -1, PREG_SPLIT_NO_EMPTY) ?: [],
            fn (string $word) => in_array(mb_strtolower($word), $topic, true),
        );

        // The engine read none of the leftover as topic: do not trust it to empty the search.
        return $kept === [] ? $result : $result->withLeftover(implode(' ', $kept), $ai->engine);
    }

    protected function normalize(ParsedQuery $parsed, string $query, ModelSchema $schema): ParseResult
    {
        return (new FilterNormalizer(
            $schema,
            (int) config('search-chips.limits.max_filters', 10),
            $query,
        ))->normalize($parsed);
    }

    /**
     * The rules reading is exact where it matched, so it stays. The AI
     * reading fills the gaps: it adds chips for fields the rules did not
     * read, from words the rules did not consume ("clips" for type video).
     */
    private function merge(ParseResult $ai, ParseResult $rules, string $query): ParseResult
    {
        $filters = $rules->filters->values()->all();
        $taken = [...$rules->filters->coveredSpans(), ...array_map(fn ($s) => [$s['start'], $s['end']], $rules->consumed)];
        $consumed = $rules->consumed;

        foreach ($ai->filters as $chip) {
            if ($rules->filters->hasField($chip->field)) {
                continue;
            }

            foreach ($chip->spans ?? [] as $span) {
                foreach ($taken as [$start, $end]) {
                    if ($span['start'] < $end && $start < $span['end']) {
                        continue 3; // the rules already explained these words
                    }
                }
            }

            $filters[] = $chip;
        }

        $collection = new FilterCollection($filters);

        return new ParseResult(
            filters: $collection,
            leftover: FilterNormalizer::leftoverFor($query, $collection, $consumed),
            sort: $rules->sort ?? $ai->sort,
            engine: $ai->engine,
            warnings: [...$rules->warnings, ...$ai->warnings],
            consumed: $consumed,
        );
    }
}
