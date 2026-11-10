<?php

use Meirdick\SearchChips\Data\FilterCollection;
use Meirdick\SearchChips\Facades\SearchChips;
use Meirdick\SearchChips\ParseResult;
use Meirdick\SearchChips\Tests\Fixtures\Post;

beforeEach(function () {
    SearchChips::register(Post::class, 'posts');
});

it('records parses and asserts on them', function () {
    $fake = SearchChips::fake();

    SearchChips::for('posts')->search('videos by Ada');

    $fake->assertParsed('videos by Ada', 'posts');
    $fake->assertParsed('videos by Ada');
});

it('fails the assertion when nothing was parsed', function () {
    expect(fn () => SearchChips::fake()->assertParsed('nope'))
        ->toThrow(Exception::class, 'No search-chips parse was recorded');
});

it('stubs a parse result per alias', function () {
    $stub = new ParseResult(new FilterCollection([]), 'stubbed', null, 'fake');

    SearchChips::fake()->shouldReturn('posts', $stub);

    expect(SearchChips::for('posts')->search('anything')->leftover())->toBe('stubbed')
        ->and(SearchChips::for('posts')->search('anything')->engine())->toBe('fake');
});

it('falls back to the real rules engine when nothing is stubbed', function () {
    SearchChips::fake();

    expect(SearchChips::for('posts')->search('videos')->filters())->not->toBeEmpty();
});
