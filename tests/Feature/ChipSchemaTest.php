<?php

use Illuminate\Support\Facades\DB;
use Meirdick\SearchChips\Data\ChipSet;
use Meirdick\SearchChips\Facades\SearchChips;
use Meirdick\SearchChips\Tests\Fixtures\Author;
use Meirdick\SearchChips\Tests\Fixtures\ConditionalPost;
use Meirdick\SearchChips\Tests\Fixtures\DeclaredPost;
use Meirdick\SearchChips\Tests\Fixtures\Post;

it('exposes exactly the fields a model declares', function () {
    $schema = SearchChips::for(DeclaredPost::class)->schema();

    expect(array_keys($schema->fields))->toBe(['title', 'type', 'views', 'engagement_rate', 'is_featured', 'published_at', 'author.name'])
        ->and($schema->field('body'))->toBeNull()
        ->and($schema->searchable)->toBe(['title', 'author.name'])
        ->and($schema->orderable)->toContain('views', 'published_at')
        ->and($schema->field('type')->valueLabels)->toBe(['video' => 'Video', 'article' => 'Article', 'podcast' => 'Podcast']);
});

it('reads a declared schema without a schema query', function () {
    DB::enableQueryLog();

    SearchChips::for(DeclaredPost::class)->schema();

    expect(DB::getQueryLog())->toBeEmpty();
});

it('searches a declared model through the scope', function () {
    $ada = Author::create(['name' => 'Ada Lovelace']);
    DeclaredPost::create(['author_id' => $ada->id, 'type' => 'video', 'title' => 'Launch', 'views' => 50_000, 'published_at' => now()]);
    DeclaredPost::create(['type' => 'video', 'title' => 'Small', 'views' => 10, 'published_at' => now()]);

    expect(DeclaredPost::autoSearch('videos by Ada above 10k plays')->pluck('title')->all())->toBe(['Launch']);
});

it('limits a discovered model to an allow-list', function () {
    SearchChips::register(Post::class, 'posts', ['only' => ['title', 'type']]);

    expect(array_keys(SearchChips::schema('posts')->fields))->toBe(['type', 'title']);
});

it('does not sample row values unless asked', function () {
    Author::create(['name' => 'Ada']);
    Author::create(['name' => 'Grace']);

    SearchChips::register(Author::class, 'authors');

    expect(SearchChips::schema('authors')->field('name')->values)->toBeNull();
});

it('accepts a class or an alias', function () {
    SearchChips::register(Post::class, 'posts');

    expect(SearchChips::for(Post::class)->alias())->toBe('posts')
        ->and(SearchChips::for('posts')->modelClass())->toBe(Post::class);
});

it('keeps a conditional filter away from rows that fail its conditions', function () {
    $ada = Author::create(['name' => 'Ada Lovelace']);
    $grace = Author::create(['name' => 'Grace Hopper']);
    ConditionalPost::create(['author_id' => $ada->id, 'type' => 'video', 'title' => 'Signed', 'is_featured' => false]);
    ConditionalPost::create(['author_id' => $ada->id, 'type' => 'video', 'title' => 'Hidden', 'is_featured' => true]);
    ConditionalPost::create(['author_id' => $grace->id, 'type' => 'video', 'title' => 'Other', 'is_featured' => false]);

    expect(ConditionalPost::autoSearch('by Ada')->pluck('title')->all())->toBe(['Signed'])
        ->and(ConditionalPost::autoSearch('author: not Ada')->pluck('title')->sort()->values()->all())->toBe(['Hidden', 'Other']);
});

it('finds a row by a bare related name', function () {
    $grace = Author::create(['name' => 'Grace Hopper']);
    DeclaredPost::create(['author_id' => $grace->id, 'type' => 'video', 'title' => 'Compilers', 'published_at' => now()]);
    DeclaredPost::create(['author_id' => Author::create(['name' => 'Ada Lovelace'])->id, 'type' => 'video', 'title' => 'Engines', 'published_at' => now()]);

    expect(DeclaredPost::autoSearch('hopper')->pluck('title')->all())->toBe(['Compilers'])
        ->and(DeclaredPost::autoSearch('engines')->pluck('title')->all())->toBe(['Engines']);
});

it('does not reveal a hidden author through the keyword search', function () {
    $ada = Author::create(['name' => 'Ada Lovelace']);
    ConditionalPost::create(['author_id' => $ada->id, 'type' => 'video', 'title' => 'Signed', 'is_featured' => false]);
    ConditionalPost::create(['author_id' => $ada->id, 'type' => 'video', 'title' => 'Hidden', 'is_featured' => true]);

    expect(ConditionalPost::autoSearch('lovelace')->pluck('title')->all())->toBe(['Signed']);
});

it('edits a chip set by slot', function () {
    $chips = ChipSet::set([], 'views', 'gte', 100);
    $chips = ChipSet::set($chips, 'views', 'lte', 500);
    $chips = ChipSet::set($chips, 'views', 'gte', 200);

    expect(ChipSet::controls($chips, 'views'))->toMatchArray(['min' => 200, 'max' => 500])
        ->and(ChipSet::set($chips, 'views', 'gte', ''))->toHaveCount(1)
        ->and(ChipSet::set($chips, 'views', 'equals', 7))->toHaveCount(1);
});

it('reads the screenshot query: a synonym, a name, and a "then" typo', function () {
    $result = SearchChips::for(DeclaredPost::class)->parse('chess movies by eva with more then 100 view');

    expect($result->filters->pluck('label')->all())->toBe(['Type is Video', 'Author contains eva', 'Views greater than 100'])
        ->and($result->leftover)->toBe('chess');
});

it('keeps conditions and synonyms when a model has field overrides', function () {
    SearchChips::register(ConditionalPost::class, 'conditional', ['fields' => ['author.name' => ['label' => 'Writer']]]);
    SearchChips::register(DeclaredPost::class, 'declared', ['fields' => ['type' => ['synonyms' => ['reel' => 'video']]]]);

    $author = SearchChips::for('conditional')->schema()->field('author.name');
    $type = SearchChips::for('declared')->schema()->field('type');

    expect($author->label)->toBe('Writer')
        ->and($author->conditions)->toBe(['is_featured' => false])
        ->and($type->synonymFor('reel'))->toBe('video')
        ->and($type->synonyms)->toBe(SearchChips::for(DeclaredPost::class)->schema()->field('type')->synonyms + ['reel' => 'video']);
});

it('keeps the other bound when one bound of a range changes', function () {
    $chips = [['id' => 'r:1', 'field' => 'views', 'operator' => 'between', 'value' => [1000, 50000]]];

    expect(ChipSet::controls(ChipSet::set($chips, 'views', 'lte', 20000), 'views'))->toMatchArray(['min' => 1000, 'max' => 20000])
        ->and(ChipSet::controls(ChipSet::set($chips, 'views', 'gte', 5000), 'views'))->toMatchArray(['min' => 5000, 'max' => 50000])
        ->and(ChipSet::controls(ChipSet::set($chips, 'views', 'lte', ''), 'views'))->toMatchArray(['min' => 1000, 'max' => null])
        ->and(ChipSet::set($chips, 'views', 'equals', 7))->toHaveCount(1);

    $dates = ChipSet::set([['field' => 'published_at', 'operator' => 'between', 'value' => ['2026-01-01', '2026-03-31']]], 'published_at', 'before', '2026-02-01');

    expect(array_column($dates, 'operator'))->toBe(['after', 'before']);
});

it('writes the label of a chip itself', function () {
    $search = SearchChips::for(DeclaredPost::class)->applyChips([
        ['field' => 'views', 'operator' => 'lte', 'value' => 20000, 'label' => 'Views between 1 – 2'],
    ]);

    expect($search->toProps()['chips'][0]['label'])->toBe('Views at most 20,000');
});
