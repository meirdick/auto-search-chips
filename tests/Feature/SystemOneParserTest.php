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
