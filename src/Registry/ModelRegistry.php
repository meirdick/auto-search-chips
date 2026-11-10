<?php

namespace Meirdick\SearchChips\Registry;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Meirdick\SearchChips\Data\ModelSchema;
use Meirdick\SearchChips\Discovery\SchemaDiscovery;
use Meirdick\SearchChips\Exceptions\InvalidModelException;
use Meirdick\SearchChips\Exceptions\UnknownModelAliasException;

/**
 * The security boundary: developer-named aliases are the only model handle
 * the HTTP surface ever accepts. Class names from request input never
 * resolve to anything.
 */
final class ModelRegistry
{
    /** @var array<string, RegisteredModel> */
    private array $models = [];

    /** @var array<string, string> */
    private array $aliasesByClass = [];

    public function __construct(private readonly SchemaDiscovery $discovery) {}

    /**
     * Register a model under a developer-chosen alias.
     *
     * @param  class-string<Model>  $modelClass
     */
    public function register(string $modelClass, ?string $alias = null, array $options = [], bool $exposed = true): RegisteredModel
    {
        if (! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            throw new InvalidModelException("[{$modelClass}] is not an Eloquent model class.");
        }

        $alias ??= Str::plural(Str::kebab(class_basename($modelClass)));

        if (! preg_match('/^[a-z0-9][a-z0-9_-]*$/', $alias)) {
            throw new InvalidModelException('Aliases must contain only lowercase letters, digits, dashes, or underscores.');
        }

        $registered = new RegisteredModel($modelClass, $alias, $options, $exposed && ($options['http'] ?? true) !== false);

        $this->models[$alias] = $registered;
        $this->aliasesByClass[$modelClass] = $alias;

        return $registered;
    }

    /**
     * Register every model found in a directory under the app's models
     * namespace, proposing slug aliases ("posts") for review. Skips
     * abstract classes.
     *
     * @return array<int, string> the aliases registered
     */
    public function discover(string $directory, string $baseNamespace): array
    {
        $registered = [];

        foreach (glob(rtrim($directory, '/').'/*.php') ?: [] as $file) {
            $class = $baseNamespace.'\\'.basename($file, '.php');

            if (! class_exists($class) || (new \ReflectionClass($class))->isAbstract()) {
                continue;
            }

            if (! is_subclass_of($class, Model::class)) {
                continue;
            }

            $registered[] = $this->register($class)->alias;
        }

        return $registered;
    }

    public function get(string $alias): RegisteredModel
    {
        if (! isset($this->models[$alias])) {
            throw new UnknownModelAliasException("Unknown model alias [{$alias}].");
        }

        return $this->models[$alias];
    }

    public function has(string $alias): bool
    {
        return isset($this->models[$alias]);
    }

    public function aliasFor(string $modelClass): ?string
    {
        return $this->aliasesByClass[$modelClass] ?? null;
    }

    /** @return array<string, RegisteredModel> */
    public function all(): array
    {
        return $this->models;
    }

    public function forget(string $alias): void
    {
        if ($model = $this->models[$alias] ?? null) {
            unset($this->aliasesByClass[$model->modelClass], $this->models[$alias]);
        }
    }

    public function flush(): void
    {
        $this->models = [];
        $this->aliasesByClass = [];
    }

    /**
     * The schema of a registered model: memoized for the request, and kept
     * in the cache store when config('search-chips.cache.schema') is on.
     */
    public function schema(RegisteredModel|string $model): ModelSchema
    {
        $registered = is_string($model) ? $this->get($model) : $model;

        if ($registered->schema !== null) {
            return $registered->schema;
        }

        $discover = fn (): ModelSchema => $this->discovery->discover($registered->modelClass, $registered->options);

        if (! config('search-chips.cache.schema', false)) {
            return $registered->schema = $discover();
        }

        return $registered->schema = self::cache()->rememberForever($this->schemaKey($registered), $discover);
    }

    /** Discover again and replace the cached schema. */
    public function refreshSchema(RegisteredModel $registered): ModelSchema
    {
        $registered->schema = $this->discovery->discover($registered->modelClass, $registered->options);

        self::cache()->forever($this->schemaKey($registered), $registered->schema);

        return $registered->schema;
    }

    /** Drop every cached schema and cached parse. */
    public function clearCache(): void
    {
        self::cache()->forever('search-chips:epoch', self::epoch() + 1);

        foreach ($this->models as $registered) {
            $registered->schema = null;
        }
    }

    /** A counter in every cache key; raising it invalidates all of them. */
    public static function epoch(): int
    {
        return (int) self::cache()->get('search-chips:epoch', 0);
    }

    public static function cache(): Repository
    {
        return Cache::store(config('search-chips.cache.store'));
    }

    private function schemaKey(RegisteredModel $registered): string
    {
        return 'search-chips:schema:'.self::epoch().':'.$registered->alias.':'.md5($registered->modelClass);
    }
}
