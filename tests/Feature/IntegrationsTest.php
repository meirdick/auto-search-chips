<?php

use Illuminate\Support\Facades\Http;
use Meirdick\SearchChips\Facades\SearchChips;
use Meirdick\SearchChips\Tests\Fixtures\DeclaredPost;
use Meirdick\SearchChips\Tests\Fixtures\IndexedPost;
use Meirdick\SearchChips\Tests\Fixtures\ScoutPost;
use Meirdick\SearchChips\Tests\Fixtures\StringDatePost;
use Meirdick\WorkersAi\Extraction;
use Meirdick\WorkersAi\Responses\Data\ExtractedValue;

describe('meirdick/laravel-cf-workersai transport', function () {
    beforeEach(function () {
        config()->set('search-chips.engine', 'system-one');
        config()->set('ai.providers.workers-ai', ['driver' => 'workers-ai', 'key' => 'token', 'account_id' => 'account']);
        SearchChips::register(DeclaredPost::class, 'posts');
    });

    it('sends the Clairic request through the package when its provider is configured', function () {
        Http::fake();
        Extraction::fake([[
            'author.name' => new ExtractedValue(['type' => 'text', 'value' => 'Ada', 'confidence' => 0.99, 'p_null' => 0.01, 'span' => [16, 19]]),
        ]]);

        $result = SearchChips::for('posts')->parse('clips made with Ada');

        expect($result->engine)->toBe('system-one')
            ->and($result->filters->first()->label)->toBe('Author contains Ada');

        Extraction::assertExtracted(fn ($prompt) => $prompt->state === 'clips made with Ada' && array_key_exists('views', $prompt->fields));
        Http::assertNothingSent(); // not the direct REST transport
    });

    it('uses the direct transport when asked', function () {
        config()->set('search-chips.ai.cloudflare', ['account_id' => 'account', 'api_token' => 'token', 'transport' => 'direct']);
        Http::fake(['*/ai/run/*' => Http::response(['result' => ['answers' => []]])]);
        Extraction::fake();

        SearchChips::for('posts')->parse('clips made with Ada');

        Http::assertSentCount(1);
        Extraction::assertNothingExtracted();
    });
});

describe('Laravel Scout', function () {
    beforeEach(function () {
        config()->set('scout.driver', 'collection');
        SearchChips::register(ScoutPost::class, 'scout-posts');

        ScoutPost::create(['type' => 'video', 'title' => 'Rockets explained', 'views' => 50_000, 'published_at' => now()]);
        ScoutPost::create(['type' => 'article', 'title' => 'Rockets in history', 'views' => 20, 'published_at' => now()]);
        ScoutPost::create(['type' => 'video', 'title' => 'Garden notes', 'views' => 90_000, 'published_at' => now()]);
    });

    it('sends keywords through Scout and chips through Eloquent', function () {
        $search = SearchChips::for('scout-posts')->search('videos about rockets above 10k views');

        expect($search->usesScout())->toBeTrue()
            ->and($search->leftover())->toBe('rockets')
            ->and($search->get()->pluck('title')->all())->toBe(['Rockets explained'])
            ->and($search->count())->toBe(1)
            ->and($search->paginate()->total())->toBe(1)
            ->and($search->query()->toRawSql())->not->toContain('like');
    });

    it('does not call Scout when the query has no keyword text', function () {
        expect(SearchChips::for('scout-posts')->search('videos')->query()->toRawSql())->not->toContain(' in (');
    });

    it('returns nothing when Scout finds nothing', function () {
        expect(SearchChips::for('scout-posts')->search('videos about submarines')->count())->toBe(0);
    });

    it('can be turned off', function () {
        config()->set('search-chips.scout.enabled', false);

        expect(SearchChips::for('scout-posts')->search('rockets')->query()->toRawSql())->toContain('like');
    });

    it('keeps the authorize option', function () {
        SearchChips::register(ScoutPost::class, 'guarded-scout', ['authorize' => fn () => false]);

        $this->postJson('/search-chips/guarded-scout/parse', ['query' => 'videos about rockets'])->assertForbidden();
        $this->postJson('/search-chips/scout-posts/parse', ['query' => 'videos about rockets'])->assertOk()->assertJsonPath('count', 1);
    });

    it('prints the index settings a model needs', function () {
        SearchChips::register(IndexedPost::class, 'indexed');

        $this->artisan('search-chips:scout', ['alias' => 'indexed'])
            ->expectsOutputToContain("'filterableAttributes' => ['title', 'type', 'views', 'engagement_rate', 'is_featured', 'published_at', 'body'],")
            ->expectsOutputToContain("'sortableAttributes' => ['views', 'published_at'],")
            ->expectsOutputToContain("['name' => 'views', 'type' => 'int64', 'sort' => true],")
            ->expectsOutputToContain("['name' => 'published_at', 'type' => 'int64', 'optional' => true, 'sort' => true],")
            ->expectsOutputToContain("'search-parameters' => ['query_by' => 'title'],")
            ->expectsOutputToContain('author.name, internal_note')
            ->assertSuccessful();

        SearchChips::register(DeclaredPost::class, 'declared');

        $this->artisan('search-chips:scout', ['alias' => 'declared'])
            ->expectsOutputToContain('does not use Laravel\\Scout\\Searchable')
            ->assertFailed();
    });
});

it('keeps a date chip in the database when the Meilisearch index stores date strings', function () {
    config()->set('scout.driver', 'meilisearch');
    config()->set('scout.meilisearch.index-settings', [
        IndexedPost::class => ['filterableAttributes' => ['published_at', 'views']],
        StringDatePost::class => ['filterableAttributes' => ['published_at', 'views']],
    ]);

    SearchChips::register(IndexedPost::class, 'timestamps');
    SearchChips::register(StringDatePost::class, 'strings');

    $sentence = 'rockets after 2025-01-01';

    expect(SearchChips::for('timestamps')->search($sentence)->usesScoutFilters())->toBeTrue()
        ->and(SearchChips::for('strings')->search($sentence)->usesScoutFilters())->toBeFalse()
        ->and(SearchChips::for('strings')->search('rockets above 100 views')->usesScoutFilters())->toBeTrue();
});
