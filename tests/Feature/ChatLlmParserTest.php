<?php

use Laravel\Ai\StructuredAnonymousAgent;
use Meirdick\SearchChips\Facades\SearchChips;
use Meirdick\SearchChips\Tests\Fixtures\DeclaredPost;

beforeEach(function () {
    config()->set('search-chips.engine', 'ai');
    config()->set('search-chips.ai.chat.provider', 'openai');
    config()->set('search-chips.ai.chat.model', 'gpt-test');
    SearchChips::register(DeclaredPost::class, 'posts');
});

it('is unavailable until provider and model are set', function () {
    config()->set('search-chips.ai.chat.model', null);

    expect(SearchChips::refinable('posts'))->toBeFalse();
});

it('maps a structured chat response into chips and finds the spans itself', function () {
    StructuredAnonymousAgent::fake([[
        'filters' => [
            ['field' => 'type', 'operator' => 'equals', 'value' => 'video', 'values' => null, 'negated' => false, 'confidence' => 0.95, 'text' => 'clips'],
            ['field' => 'views', 'operator' => 'between', 'value' => null, 'values' => ['100', '500'], 'negated' => false, 'confidence' => 0.9, 'text' => 'between 100 and 500 views'],
            ['field' => 'title', 'operator' => 'contains', 'value' => 'x', 'values' => null, 'negated' => false, 'confidence' => 0.9, 'text' => 'words the query lacks'],
        ],
        'leftover' => 'rockets',
    ]]);

    $result = SearchChips::for('posts')->parse('clips between 100 and 500 views rockets');

    expect($result->engine)->toBe('ai')
        ->and($result->filters->pluck('field')->all())->toBe(['views', 'type'])
        ->and($result->filters->firstWhere('field', 'views')->value)->toBe([100.0, 500.0])
        ->and($result->leftover)->toBe('rockets');
});
