<?php

use Illuminate\Support\Facades\Http;
use Meirdick\SearchChips\Contracts\Parser;
use Meirdick\SearchChips\Data\ModelSchema;
use Meirdick\SearchChips\Data\ParsedQuery;
use Meirdick\SearchChips\Facades\SearchChips;
use Meirdick\SearchChips\Tests\Fixtures\Author;
use Meirdick\SearchChips\Tests\Fixtures\DeclaredPost;

function clairic(array $answers): void
{
    Http::fake(['*/ai/run/*' => Http::response(['result' => ['model' => 'clairic', 'answers' => $answers], 'success' => true])]);
}

function answer(string $type, mixed $value, ?array $span = null, float $confidence = 0.99): array
{
    return array_filter(['type' => $type, 'value' => $value, 'confidence' => $confidence, 'p_null' => 0.01, 'span' => $span], fn ($v) => $v !== null);
}

beforeEach(function () {
    config()->set('search-chips.engine', 'system-one');
    config()->set('search-chips.ai.cloudflare.account_id', 'account');
    config()->set('search-chips.ai.cloudflare.api_token', 'token');
    SearchChips::register(DeclaredPost::class, 'posts');
});

it('returns the rules reading at once and marks it refinable', function () {
    Http::fake();

    $result = SearchChips::for('posts')->parse('videos above 10k views', refine: false);

    expect($result->engine)->toBe('rules')
        ->and($result->refinable)->toBeTrue()
        ->and($result->filters)->toHaveCount(2);

    Http::assertNothingSent();
});

it('is not refinable when only rules are configured', function () {
    config()->set('search-chips.engine', 'rules');

    expect(SearchChips::for('posts')->parse('videos', refine: false)->refinable)->toBeFalse();
});

it('reads each operator from its own span', function () {
    //          0         1         2         3         4
    //          0123456789012345678901234567890123456789012345
    $query = 'engagement under 5% with views above 10k';

    clairic([
        'engagement_rate' => answer('number', 5, [17, 19]),
        'views' => answer('number', 10000, [37, 40]),
    ]);

    $filters = SearchChips::for('posts')->parse($query)->filters;

    expect($filters->firstWhere('field', 'engagement_rate')->operator)->toBe('lt')
        ->and($filters->firstWhere('field', 'views')->operator)->toBe('gt');
});

it('finds the span of a computed number by its value', function () {
    clairic([
        'engagement_rate' => answer('number', 5),
        'views' => answer('number', 10000),
    ]);

    $filters = SearchChips::for('posts')->parse('engagement under 5 with views above 10k')->filters;

    expect($filters->firstWhere('field', 'engagement_rate')->operator)->toBe('lt')
        ->and($filters->firstWhere('field', 'views')->operator)->toBe('gt');
});

it('turns a boolean answer into a chip', function () {
    clairic(['is_featured' => answer('text', 'featured', [0, 8])]);

    $chip = SearchChips::for('posts')->parse('featured posts')->filters->firstWhere('field', 'is_featured');

    expect($chip)->not->toBeNull()->and($chip->operator)->toBe('is_true');
});

it('reads a negative boolean', function () {
    clairic(['is_featured' => answer('text', 'not featured', [0, 12])]);

    expect(SearchChips::for('posts')->parse('not featured posts')->filters->firstWhere('field', 'is_featured')->operator)->toBe('is_false');
});

it('marks a borderline answer as a suggestion and does not apply it', function () {
    DeclaredPost::create(['type' => 'video', 'title' => 'Launch', 'published_at' => now()]);
    DeclaredPost::create(['type' => 'article', 'title' => 'Clip art guide', 'published_at' => now()]);

    clairic(['type' => answer('text', 'video', [0, 4], confidence: 0.7)]);

    $pending = SearchChips::for('posts')->search('clip');

    // The type filter is only offered, so the word still works as a keyword.
    expect($pending->filters()->first()->suggested)->toBeTrue()
        ->and($pending->get()->pluck('title')->all())->toBe(['Clip art guide']);
});

it('drops an answer below the confidence threshold', function () {
    clairic(['type' => answer('text', 'video', [0, 4], confidence: 0.3)]);

    expect(SearchChips::for('posts')->parse('clip things')->filters)->toBeEmpty();
});

it('converts character spans to byte spans', function () {
    clairic(['views' => answer('number', 10000, [11, 14])]);

    $result = SearchChips::for('posts')->parse('café above 10k');

    expect($result->filters->first()->operator)->toBe('gt')
        ->and($result->leftover)->toBe('café');
});

it('leaves no comparator or field words in the keyword search', function () {
    clairic([
        'type' => answer('text', 'videos', [0, 6]),
        'author.name' => answer('text', 'Ada', [10, 13]),
        'views' => answer('number', 10000, [20, 23]),
    ]);

    $result = SearchChips::for('posts')->parse('videos by Ada above 10k views about rockets');

    expect($result->filters)->toHaveCount(3)
        ->and($result->filters->firstWhere('field', 'author.name')->operator)->toBe('contains')
        ->and($result->leftover)->toBe('rockets');
});

it('sends the request shape Clairic documents', function () {
    clairic([]);

    SearchChips::for('posts')->parse('videos');

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/accounts/account/ai/run/@cf/cloudflare/clairic')
        && $request['model'] === 'clairic'
        && $request['state'] === 'videos'
        && isset($request['reference_date'], $request['fields']['views']));
});

it('caches an AI parse', function () {
    clairic(['type' => answer('text', 'video', [0, 6])]);

    SearchChips::for('posts')->parse('videos');
    SearchChips::for('posts')->parse('Videos');

    Http::assertSentCount(1);
});

it('forgets cached parses on clear', function () {
    clairic(['type' => answer('text', 'video', [0, 6])]);

    SearchChips::for('posts')->parse('videos');
    $this->artisan('search-chips:clear')->assertSuccessful();
    SearchChips::for('posts')->parse('videos');

    Http::assertSentCount(2);
});

it('keeps the rules chips when the engine fails', function () {
    Http::fake(['*' => Http::response('nope', 500)]);

    $result = SearchChips::for('posts')->parse('videos above 10k views');

    expect($result->engine)->toBe('rules')->and($result->filters)->toHaveCount(2);
});

it('runs a custom engine added with extend', function () {
    SearchChips::extend('mine', fn () => new class implements Parser
    {
        public function available(): bool
        {
            return true;
        }

        public function parse(string $query, ModelSchema $schema): ParsedQuery
        {
            return new ParsedQuery([
                ['field' => 'type', 'operator' => 'equals', 'value' => 'podcast', 'source' => 'ai', 'spans' => [['start' => 0, 'end' => 4]]],
            ], null, null, 'mine');
        }
    });

    config()->set('search-chips.engine', 'mine');

    $result = SearchChips::for('posts')->parse('pods please');

    expect($result->engine)->toBe('mine')->and($result->filters->first()->value)->toBe('podcast');
});

it('names the valid engines when the engine is unknown', function () {
    config()->set('search-chips.engine', 'nope');

    SearchChips::for('posts')->parse('videos');
})->throws(InvalidArgumentException::class, 'SearchChips::extend()');

it('offers a confident guess as a suggested chip', function () {
    clairic(['author.name' => ['type' => 'text', 'value' => null, 'guess' => 'Maya', 'confidence' => 0.99, 'p_null' => 0.54, 'span' => [11, 15]]]);

    $chip = SearchChips::for('posts')->parse('clips with Maya')->filters->firstWhere('field', 'author.name');

    expect($chip->suggested)->toBeTrue()->and($chip->value)->toBe('Maya');
});

it('ignores a guess the model does not believe', function () {
    clairic(['author.name' => ['type' => 'text', 'value' => null, 'guess' => 'popular things', 'confidence' => 0.6, 'p_null' => 0.98, 'span' => [0, 14]]]);

    expect(SearchChips::for('posts')->parse('popular things')->filters)->toBeEmpty();
});

it('keeps an AI chip when the browser sends the chip set back', function () {
    DeclaredPost::create(['author_id' => Author::create(['name' => 'Ada Lovelace'])->id, 'type' => 'video', 'title' => 'A', 'published_at' => now()]);
    DeclaredPost::create(['author_id' => Author::create(['name' => 'Grace Hopper'])->id, 'type' => 'video', 'title' => 'B', 'published_at' => now()]);

    clairic(['author.name' => answer('text', 'Ada', [16, 19])]);

    $chips = SearchChips::for('posts')->search('clips made with Ada')->chips();
    $again = SearchChips::for('posts')->applyChips(json_decode(json_encode($chips), true), '');

    expect($chips)->toHaveCount(1)
        ->and($again->filters())->toHaveCount(1)
        ->and($again->filters()->first()->source)->toBe('system-one')
        ->and($again->count())->toBe(1);
});

it('keeps the words of a suggested chip in the keyword search', function () {
    DeclaredPost::create(['type' => 'video', 'title' => 'Pain diary', 'published_at' => now()]);
    DeclaredPost::create(['type' => 'video', 'title' => 'Garden', 'published_at' => now()]);

    clairic(['author.name' => ['type' => 'text', 'value' => null, 'guess' => 'pain', 'confidence' => 0.95, 'p_null' => 0.6, 'span' => [0, 4]]]);

    $pending = SearchChips::for('posts')->search('pain');

    expect($pending->filters()->first()->suggested)->toBeTrue()
        ->and($pending->leftover())->toBe('pain')
        ->and($pending->get()->pluck('title')->all())->toBe(['Pain diary']);
});

it('does not call a model from a test run when the engine is auto', function () {
    config()->set('search-chips.engine', 'auto');
    Http::fake();

    expect(SearchChips::refinable('posts'))->toBeFalse();

    SearchChips::for('posts')->parse('videos');

    Http::assertNothingSent();
});

it('lets the engine drop glue words from the keyword search and never add one', function () {
    clairic(['search_keywords' => ['type' => 'list', 'value' => ['videos', 'heating pad', 'submarines'], 'spans' => [[0, 6]], 'confidence' => 0.4]]);

    $result = SearchChips::for('posts')->parse('heating pad thing whenever');

    expect($result->leftover)->toBe('heating pad')
        ->and($result->engine)->toBe('system-one');

    Http::assertSent(fn ($request) => $request['fields']['search_keywords']['type'] === 'list');
});

it('keeps the leftover when the engine names none of its words', function () {
    clairic(['search_keywords' => ['type' => 'list', 'value' => ['unrelated'], 'confidence' => 0.4]]);

    expect(SearchChips::for('posts')->parse('heating pad')->leftover)->toBe('heating pad');
});
