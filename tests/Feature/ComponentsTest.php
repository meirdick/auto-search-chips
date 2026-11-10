<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Meirdick\SearchChips\Facades\SearchChips;
use Meirdick\SearchChips\Tests\Fixtures\Author;
use Meirdick\SearchChips\Tests\Fixtures\DeclaredPost;

beforeEach(function () {
    SearchChips::register(DeclaredPost::class, 'posts');
});

it('renders the blade component with its script once', function () {
    $html = Blade::render('<x-search-chips::search for="posts" /><x-search-chips::search for="posts" />');

    expect(substr_count($html, '/search-chips/search-chips.js'))->toBe(1)
        ->and($html)->toContain('x-data="searchChips(')
        ->and($html)->toContain('data-search-chips="posts"');
});

it('refuses to render the blade component for an unknown alias', function () {
    Blade::render('<x-search-chips::search for="nope" />');
})->throws(Exception::class, 'Unknown search alias [nope]');

it('serves the component script', function () {
    $this->get('/search-chips/search-chips.js')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
});

it('keeps a model that was only used in code off the routes', function () {
    Author::create(['name' => 'Ada']);
    SearchChips::for(Author::class)->search('Ada')->count();

    $this->postJson('/search-chips/authors/parse', ['query' => 'Ada'])->assertNotFound();
});

it('ships auth and a rate limit as the route defaults', function () {
    $defaults = require __DIR__.'/../../config/search-chips.php';

    expect($defaults['routes']['middleware'])->toBe(['web', 'auth', 'throttle:search-chips'])
        ->and($defaults['discovery']['sample_values'])->toBeFalse();
});

it('builds a search from a page request and returns page props', function () {
    DeclaredPost::create(['type' => 'video', 'title' => 'Launch', 'views' => 50_000, 'published_at' => now()]);
    DeclaredPost::create(['type' => 'article', 'title' => 'Notes', 'views' => 10, 'published_at' => now()]);

    $fromText = SearchChips::for('posts')->fromRequest(Request::create('/posts', 'GET', ['q' => 'videos']));

    expect($fromText->count())->toBe(1)
        ->and($fromText->toProps())->toMatchArray(['query' => 'videos', 'count' => 1, 'leftover' => '']);

    $chips = json_encode($fromText->toProps()['chips']);
    $fromChips = SearchChips::for('posts')->fromRequest(Request::create('/posts', 'GET', ['q' => 'videos', 'chips' => $chips]));

    expect($fromChips->count())->toBe(1)
        ->and($fromChips->toProps()['query'])->toBe('videos')
        ->and(SearchChips::for('posts')->fromRequest(Request::create('/posts'))->count())->toBe(2);
});

it('applies a base query to every search of a model', function () {
    DeclaredPost::create(['type' => 'video', 'title' => 'Mine', 'views' => 5, 'published_at' => now()]);
    DeclaredPost::create(['type' => 'video', 'title' => 'Theirs', 'views' => 500, 'published_at' => now()]);

    SearchChips::register(DeclaredPost::class, 'small-posts', ['query' => fn ($query) => $query->where('views', '<', 100)]);

    expect(SearchChips::for('small-posts')->search('videos')->get()->pluck('title')->all())->toBe(['Mine']);
});

it('serializes date chips as plain strings that apply again', function () {
    DeclaredPost::create(['type' => 'video', 'title' => 'New', 'published_at' => now()]);
    DeclaredPost::create(['type' => 'video', 'title' => 'Old', 'published_at' => now()->subYears(2)]);

    $chips = SearchChips::for('posts')->search('published after 2025-01-01')->chips();

    expect($chips[0]['value'])->toBeString()
        ->and(SearchChips::for('posts')->applyChips(json_decode(json_encode($chips), true))->count())->toBe(1);
});

it('installs the react components', function () {
    $target = resource_path('js/components/search-chips');
    File::deleteDirectory($target);

    $this->artisan('search-chips:install', ['--stack' => 'react'])->assertSuccessful();

    expect(file_exists($target.'/core.ts'))->toBeTrue()
        ->and(file_exists($target.'/search-chips.tsx'))->toBeTrue()
        ->and(file_exists($target.'/use-search-chips.ts'))->toBeTrue();

    File::deleteDirectory($target);
});

it('adds the tailwind source line once for the livewire stack', function () {
    $css = resource_path('css/app.css');
    File::ensureDirectoryExists(dirname($css));
    File::put($css, "@import 'tailwindcss';\n@source '../views';\n\n@theme {\n}\n");

    $this->artisan('search-chips:install', ['--stack' => 'livewire'])->assertSuccessful();
    $this->artisan('search-chips:install', ['--stack' => 'livewire'])->assertSuccessful();

    $lines = explode("\n", File::get($css));

    expect(substr_count(File::get($css), 'auto-search-chips/resources/views'))->toBe(1)
        ->and($lines[2])->toContain('@source')->toContain('auto-search-chips');

    File::delete($css);
});
