<?php

use Illuminate\Support\Facades\Http;
use Meirdick\SearchChips\Facades\SearchChips;
use Meirdick\SearchChips\Parsing\RulesParser;
use Meirdick\SearchChips\Parsing\SystemOneParser;
use Meirdick\SearchChips\Tests\Fixtures\Post;
use Meirdick\SearchChips\Tests\Support\RecordingExtractor;

beforeEach(function () {
    config()->set('search-chips.engine', 'system-one');
    config()->set('search-chips.ai.cloudflare.account_id', 'account');
    config()->set('search-chips.ai.cloudflare.api_token', 'token');
    SearchChips::register(Post::class, 'posts');
});

it('maps the documented Clairic answers envelope into chips', function () {
    Http::fake(['*/ai/run/*' => Http::response([
        'model' => 'clairic',
        'answers' => [
            'type' => ['type' => 'text', 'value' => 'video', 'confidence' => 0.99, 'p_null' => 0.01, 'span' => [0, 6]],
            'views' => ['type' => 'number', 'value' => 10000, 'confidence' => 0.98, 'p_null' => 0.01],
        ],
        'truncated' => false,
        'usage' => ['input_tokens' => 10, 'output_tokens' => 0],
    ])]);

    $result = SearchChips::for('posts')->parse('videos above 10k views');

    expect($result->filters->pluck('field')->all())->toBe(['type', 'views'])
        ->and($result->filters->firstWhere('field', 'views')->operator)->toBe('gt')
        ->and($result->engine)->toBe('system-one');
});

it('uses an installed Workers AI extractor ahead of the direct request', function () {
    Http::fake();
    $parser = new SystemOneParser(
        ['transport' => 'auto'],
        app(RulesParser::class),
        new RecordingExtractor,
    );

    $result = $parser->parse('videos above 10k views', SearchChips::for('posts')->schema());

    expect(collect($result->filters)->pluck('field')->all())->toBe(['type']);
    Http::assertNothingSent();
});

it('does not fall back to chat when Clairic fails', function () {
    Http::fake(['*' => Http::response([], 503)]);

    $result = SearchChips::for('posts')->parse('videos');

    expect($result->engine)->toBe('rules')
        ->and($result->filters)->toHaveCount(1);
});

function clairicAndClef(array $type, string $choice, float $probability): void
{
    Http::fake([
        '*/ai/run/@cf/cloudflare/clairic' => Http::response(['result' => ['answers' => ['type' => $type]]]),
        '*/ai/run/@cf/cloudflare/clef-flash' => Http::response(['result' => ['answers' => ['q0' => [
            'type' => 'choice', 'choice' => $choice, 'probabilities' => [$choice => $probability], 'confidence' => $probability,
        ]]]]),
    ]);
}

it('asks the decision model which value an unknown word means', function () {
    clairicAndClef(['type' => 'text', 'value' => 'movies', 'confidence' => 0.92, 'p_null' => 0.31, 'span' => [7, 13]], 'v0', 0.9);

    $result = SearchChips::for('posts')->parse('garden movies');
    $type = $result->filters->firstWhere('field', 'type');

    expect($type->value)->toBe('video')
        ->and($type->suggested)->toBeFalse()
        ->and($result->leftover)->toBe('garden');

    Http::assertSent(fn ($request) => str_ends_with($request->url(), 'clef-flash')
        && $request['model'] === 'clef-flash'
        && $request['questions']['q0']['criteria'] === ['v0' => 'video', 'v1' => 'article', 'v2' => 'podcast', 'none' => 'The word means none of the other options.']);
});

it('places a guessed word that the decision model is sure of', function () {
    clairicAndClef(['type' => 'text', 'value' => null, 'guess' => 'clips', 'confidence' => 0.95, 'p_null' => 0.75, 'span' => [0, 5]], 'v0', 0.9);

    expect(SearchChips::for('posts')->parse('clips about rockets')->filters->firstWhere('field', 'type')->value)->toBe('video');
});

it('keeps the word as text when the decision model is not sure or says none', function (string $choice, float $probability) {
    clairicAndClef(['type' => 'text', 'value' => 'shows', 'confidence' => 0.95, 'p_null' => 0.2, 'span' => [0, 5]], $choice, $probability);

    $result = SearchChips::for('posts')->parse('shows about rockets');

    expect($result->filters->firstWhere('field', 'type'))->toBeNull()
        ->and($result->leftover)->toContain('shows');
})->with([['v0', 0.5], ['none', 0.95]]);

it('does not ask about a known value, a topic word, or when the step is off', function (array $type, bool $enabled) {
    config()->set('search-chips.ai.cloudflare.match_values', $enabled);
    clairicAndClef($type, 'v0', 0.99);

    SearchChips::for('posts')->parse('videos about rockets');

    Http::assertSentCount(1);
})->with([
    'known value' => [['type' => 'text', 'value' => 'videos', 'confidence' => 0.99, 'p_null' => 0.01, 'span' => [0, 6]], true],
    'topic word' => [['type' => 'text', 'value' => null, 'guess' => 'rockets', 'confidence' => 0.84, 'p_null' => 0.95, 'span' => [13, 20]], true],
    'step off' => [['type' => 'text', 'value' => 'movies', 'confidence' => 0.92, 'p_null' => 0.31, 'span' => [0, 6]], false],
]);

it('keeps the Clairic reading when the decision call fails', function () {
    Http::fake([
        '*/ai/run/@cf/cloudflare/clairic' => Http::response(['result' => ['answers' => [
            'type' => ['type' => 'text', 'value' => 'movies', 'confidence' => 0.92, 'p_null' => 0.31, 'span' => [0, 6]],
            'views' => ['type' => 'number', 'value' => 100, 'confidence' => 0.99, 'p_null' => 0.01],
        ]]]),
        '*' => Http::response([], 503),
    ]);

    $result = SearchChips::for('posts')->parse('movies above 100 views');

    expect($result->engine)->toBe('system-one')
        ->and($result->filters->pluck('field')->all())->toBe(['views']);
});
