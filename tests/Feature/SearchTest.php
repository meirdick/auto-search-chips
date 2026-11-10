<?php

use Meirdick\SearchChips\Exceptions\UnknownModelAliasException;
use Meirdick\SearchChips\Facades\SearchChips;
use Meirdick\SearchChips\Tests\Fixtures\Author;
use Meirdick\SearchChips\Tests\Fixtures\Post;
use Meirdick\SearchChips\Tests\Fixtures\SearchablePost;

beforeEach(function () {
    SearchChips::register(Post::class, 'posts');
});

it('searches through the fluent handle', function () {
    $author = Author::create(['name' => 'Ada Lovelace']);
    Post::create(['author_id' => $author->id, 'type' => 'video', 'title' => 'Launch', 'views' => 50_000, 'published_at' => now()]);
    Post::create(['type' => 'article', 'title' => 'Notes', 'views' => 100, 'published_at' => now()]);

    $pending = SearchChips::for('posts')->search('videos by Ada above 10k views');

    expect($pending->filters())->toHaveCount(3)
        ->and($pending->leftover())->toBe('')
        ->and($pending->count())->toBe(1)
        ->and($pending->get()->first()->title)->toBe('Launch');
});

it('filters on percent-scaled fields', function () {
    Post::create(['type' => 'video', 'title' => 'Viral', 'engagement_rate' => 0.12, 'published_at' => now()]);
    Post::create(['type' => 'video', 'title' => 'Quiet', 'engagement_rate' => 0.01, 'published_at' => now()]);

    $titles = SearchChips::for('posts')->search('engagement above 8%')->get()->pluck('title');

    expect($titles)->toContain('Viral')->not->toContain('Quiet');
});

it('keeps leftover words as a keyword search', function () {
    Post::create(['type' => 'video', 'title' => 'Rockets explained', 'published_at' => now()]);
    Post::create(['type' => 'video', 'title' => 'Gardening tips', 'published_at' => now()]);

    $pending = SearchChips::for('posts')->search('videos about rockets');

    expect($pending->leftover())->toBe('rockets')
        ->and($pending->get()->pluck('title')->all())->toBe(['Rockets explained']);
});

it('applies user-edited chips and drops chips for unknown fields', function () {
    Post::create(['type' => 'video', 'title' => 'One', 'views' => 5, 'published_at' => now()]);
    Post::create(['type' => 'article', 'title' => 'Two', 'views' => 5, 'published_at' => now()]);

    $pending = SearchChips::for('posts')->applyChips([
        ['field' => 'type', 'operator' => 'equals', 'value' => 'video'],
        ['field' => 'password', 'operator' => 'equals', 'value' => 'secret'],
    ]);

    expect($pending->filters())->toHaveCount(1)
        ->and($pending->get())->toHaveCount(1);
});

it('sorts from sort words', function () {
    Post::create(['type' => 'video', 'title' => 'Old', 'published_at' => now()->subYear()]);
    Post::create(['type' => 'video', 'title' => 'New', 'published_at' => now()]);

    $titles = SearchChips::for('posts')->search('latest videos')->get()->pluck('title');

    expect($titles->all())->toBe(['New', 'Old']);
});

it('filters through the model scope', function () {
    SearchChips::register(SearchablePost::class, 'searchable-posts');

    SearchablePost::create(['type' => 'podcast', 'title' => 'Episode', 'published_at' => now()]);
    SearchablePost::create(['type' => 'article', 'title' => 'Essay', 'published_at' => now()]);

    expect(SearchablePost::query()->autoSearch('podcasts')->count())->toBe(1);
});

it('rejects unknown aliases', function () {
    SearchChips::for('nope')->search('anything');
})->throws(UnknownModelAliasException::class);

it('paginates and reports the count', function () {
    foreach (range(1, 3) as $i) {
        Post::create(['type' => 'video', 'title' => "Video {$i}", 'published_at' => now()]);
    }

    $page = SearchChips::for('posts')->search('videos')->paginate(2);

    expect($page->total())->toBe(3)
        ->and($page->count())->toBe(2);
});

it('never treats like wildcards in user text as wildcards', function () {
    Post::create(['type' => 'article', 'title' => '50%_off sale', 'published_at' => now()]);
    Post::create(['type' => 'article', 'title' => 'Full price', 'published_at' => now()]);

    // "%" is LIKE syntax; user text must stay literal, so this matches nothing
    // rather than everything.
    expect(SearchChips::for('posts')->search('50%_off')->count())->toBe(1)
        ->and(SearchChips::for('posts')->search('50x')->count())->toBe(0);
});
