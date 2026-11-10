<?php

namespace Meirdick\SearchChips;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Meirdick\SearchChips\Applying\EloquentApplicator;
use Meirdick\SearchChips\Discovery\SchemaDiscovery;
use Meirdick\SearchChips\Livewire\Search;
use Meirdick\SearchChips\Parsing\RulesParser;
use Meirdick\SearchChips\Registry\ModelRegistry;

class SearchChipsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/search-chips.php', 'search-chips');

        $this->app->singleton(SchemaDiscovery::class);
        $this->app->singleton(RulesParser::class);
        $this->app->singleton(EloquentApplicator::class);
        $this->app->singleton(ModelRegistry::class);
        $this->app->singleton(SearchChipsManager::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/search-chips.php' => $this->app->configPath('search-chips.php'),
            ], 'search-chips-config');

            $this->publishes([
                __DIR__.'/../resources/views' => $this->app->resourcePath('views/vendor/search-chips'),
            ], 'search-chips-views');

            $this->commands([
                Commands\InstallCommand::class,
                Commands\SchemaCommand::class,
                Commands\ScoutCommand::class,
                Commands\ParseCommand::class,
                Commands\CacheCommand::class,
                Commands\ClearCommand::class,
            ]);
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'search-chips');

        RateLimiter::for('search-chips', fn (Request $request) => Limit::perMinute(
            (int) config('search-chips.routes.rate_limit', 120),
        )->by($request->user()?->getAuthIdentifier() ?: $request->ip()));

        $this->app->booted(function () {
            if (class_exists(Livewire::class) && $this->app->bound('livewire')) {
                Livewire::component('search-chips', Search::class);
            }
        });

        $this->registerConfiguredModels();

        if (config('search-chips.routes.enabled', true) && ! $this->app->routesAreCached()) {
            $this->loadRoutesFrom(__DIR__.'/../routes/search-chips.php');
        }
    }

    private function registerConfiguredModels(): void
    {
        $manager = $this->app->make(SearchChipsManager::class);

        foreach (config('search-chips.models', []) as $alias => $settings) {
            // 'posts' => Post::class
            if (is_string($settings)) {
                $manager->register($settings, is_int($alias) ? null : $alias);

                continue;
            }

            // 'posts' => ['model' => Post::class, ...options]
            if (is_array($settings) && ($model = $settings['model'] ?? null)) {
                $manager->register($model, is_int($alias) ? null : $alias, collect($settings)->except('model')->all());
            }
        }

        if (config('search-chips.auto_discover', false) && is_dir($this->app->path('Models'))) {
            $manager->discover();
        }
    }
}
