<?php

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Meirdick\SearchChips\Facades\SearchChips;
use Workbench\App\Livewire\PostList;
use Workbench\App\Models\Post;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The demo has no login, so the routes drop the default `auth`.
        config()->set('search-chips.routes.middleware', ['web', 'throttle:search-chips']);

        // `composer serve` runs PHP's built-in server, which does not expose
        // shell variables to env(). Read the Cloudflare credentials directly,
        // so `CLOUDFLARE_ACCOUNT_ID=… CLOUDFLARE_API_TOKEN=… composer serve`
        // turns on live System One parsing.
        // With SEARCH_CHIPS_VIA_PACKAGE=1 the demo has no direct credentials:
        // Clairic then runs through meirdick/laravel-cf-workersai only.
        if (getenv('SEARCH_CHIPS_VIA_PACKAGE') && getenv('CLOUDFLARE_API_TOKEN')) {
            config()->set('ai.providers.workers-ai', [
                'driver' => 'workers-ai',
                'key' => getenv('CLOUDFLARE_API_TOKEN'),
                'account_id' => getenv('CLOUDFLARE_ACCOUNT_ID'),
            ]);

            return;
        }

        foreach (['account_id' => 'CLOUDFLARE_ACCOUNT_ID', 'api_token' => 'CLOUDFLARE_API_TOKEN'] as $key => $variable) {
            if (is_string($value = getenv($variable)) && $value !== '') {
                config()->set("search-chips.ai.cloudflare.{$key}", $value);
            }
        }
    }

    public function boot(): void
    {
        SearchChips::register(Post::class, 'posts', ['columns' => ['id', 'title', 'type', 'views']]);

        Livewire::component('post-list', PostList::class);
    }
}
