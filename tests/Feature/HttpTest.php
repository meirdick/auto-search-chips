<?php

use Illuminate\Support\Facades\Gate;
use Meirdick\SearchChips\Facades\SearchChips;
use Meirdick\SearchChips\Tests\Fixtures\Post;

beforeEach(function () {
    SearchChips::register(Post::class, 'posts', [
        'columns' => ['id', 'title', 'type', 'views'],
    ]);

    Post::create(['type' => 'video', 'title' => 'Launch', 'views' => 50_000, 'published_at' => now()]);
    Post::create(['type' => 'article', 'title' => 'Notes', 'views' => 10, 'published_at' => now()]);
});

it('parses a query over http and returns chips, leftover, and count', function () {
    $this->postJson('/search-chips/posts/parse', ['query' => 'videos above 10k views'])
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('engine', 'rules')
        ->assertJsonPath('leftover', '')
        ->assertJsonCount(2, 'filters');
});

it('returns result rows when asked', function () {
    $this->postJson('/search-chips/posts/parse', ['query' => 'videos', 'with' => 'results'])
        ->assertOk()
        ->assertJsonPath('results.0.title', 'Launch')
        ->assertJsonMissingPath('results.0.body');
});

it('re-applies an edited chip set', function () {
    $parsed = $this->postJson('/search-chips/posts/parse', ['query' => 'videos above 10k views'])->json();

    $chips = collect($parsed['filters'])->reject(fn ($chip) => $chip['field'] === 'views')->values()->all();

    $this->postJson('/search-chips/posts/apply', ['chips' => $chips])
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonCount(1, 'filters');
});

it('rejects queries for unregistered aliases', function () {
    $this->postJson('/search-chips/nope/parse', ['query' => 'anything'])->assertNotFound();
});

it('validates the query', function () {
    $this->postJson('/search-chips/posts/parse', [])->assertUnprocessable();
    $this->postJson('/search-chips/posts/parse', ['query' => str_repeat('a', 400)])->assertUnprocessable();
});

it('enforces a per-model policy', function () {
    SearchChips::register(Post::class, 'guarded', ['authorize' => 'search-guarded']);

    Gate::define('search-guarded', fn () => false);

    $this->postJson('/search-chips/guarded/parse', ['query' => 'videos'])->assertForbidden();
});

it('drops chips for fields outside the schema', function () {
    $this->postJson('/search-chips/posts/apply', [
        'chips' => [
            ['field' => 'password', 'operator' => 'equals', 'value' => 'secret'],
            ['field' => 'type', 'operator' => 'equals', 'value' => 'video'],
        ],
    ])->assertOk()->assertJsonCount(1, 'filters');
});
