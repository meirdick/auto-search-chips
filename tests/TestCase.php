<?php

namespace Meirdick\SearchChips\Tests;

use Illuminate\Support\Facades\Schema;
use Laravel\Ai\AiServiceProvider;
use Laravel\Scout\ScoutServiceProvider;
use Livewire\LivewireServiceProvider;
use Meirdick\SearchChips\SearchChipsServiceProvider;
use Meirdick\WorkersAi\WorkersAiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, AiServiceProvider::class, ScoutServiceProvider::class, WorkersAiServiceProvider::class, SearchChipsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));

        $app['config']->set('search-chips.engine', 'rules');
        $app['config']->set('search-chips.routes.middleware', ['web', 'throttle:search-chips']);
        $app['config']->set('cache.default', 'array');
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('authors', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->timestamps();
        });

        Schema::create('posts', function ($table) {
            $table->id();
            $table->foreignId('author_id')->nullable()->constrained();
            $table->string('type')->default('article'); // video | article | podcast
            $table->string('title');
            $table->text('body')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->decimal('engagement_rate', 5, 4)->default(0);
            $table->boolean('is_featured')->default(false);
            $table->string('internal_note')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }
}
