<?php

use Meirdick\SearchChips\Data\ModelSchema;
use Meirdick\SearchChips\Data\Operator;
use Meirdick\SearchChips\Discovery\SchemaDiscovery;
use Meirdick\SearchChips\ParseResult;
use Meirdick\SearchChips\Parsing\FilterNormalizer;
use Meirdick\SearchChips\Parsing\RulesParser;
use Meirdick\SearchChips\Tests\Fixtures\Post;

beforeEach(function () {
    $this->schema = app(SchemaDiscovery::class)->discover(Post::class);
    $this->parser = new RulesParser;
});

function chips(string $query, ModelSchema $schema): ParseResult
{
    $parsed = app(RulesParser::class)->parse($query, $schema);

    return (new FilterNormalizer($schema, 10, $query))->normalize($parsed);
}

function chipOn(string $field, string $query, ModelSchema $schema)
{
    return chips($query, $schema)->filters->whereField($field)->first();
}

it('parses the demo query into exactly three chips with nothing left over', function () {
    $result = chips('videos by Ada above 10k views', $this->schema);

    expect($result->filters)->toHaveCount(3)
        ->and($result->leftover)->toBe('');

    $type = $result->filters->whereField('type')->first();
    expect($type->operator)->toBe(Operator::EQUALS)
        ->and($type->value)->toBe('video')
        ->and($type->spans)->toBe([['start' => 0, 'end' => 6]]);

    $author = $result->filters->whereField('author.name')->first();
    expect($author->operator)->toBe(Operator::CONTAINS)
        ->and($author->value)->toBe('Ada')
        ->and($author->spans)->toBe([['start' => 7, 'end' => 10], ['start' => 10, 'end' => 13]]);

    $views = $result->filters->whereField('views')->first();
    expect($views->operator)->toBe(Operator::GT)
        ->and($views->value)->toBe(10_000.0)
        ->and($views->spans)->toBe([['start' => 14, 'end' => 23], ['start' => 24, 'end' => 29]]);
});

it('reads comparators and number suffixes', function (string $query, string $operator, float $value) {
    $views = chipOn('views', $query, $this->schema);

    expect($views)->not->toBeNull()
        ->and($views->operator)->toBe($operator)
        ->and($views->value)->toBe($value);
})->with([
    ['above 10k views', Operator::GT, 10_000.0],
    ['over 1.5m views', Operator::GT, 1_500_000.0],
    ['at least 500 views', Operator::GTE, 500.0],
    ['views >= 2k', Operator::GTE, 2_000.0],
    ['under 100 views', Operator::LT, 100.0],
    ['below 3 views', Operator::LT, 3.0],
    ['at most 1bn views', Operator::LTE, 1_000_000_000.0],
    ['more than 2,500 views', Operator::GT, 2_500.0],
]);

it('reads between ranges', function () {
    $views = chipOn('views', 'between 1k and 5k views', $this->schema);

    expect($views->operator)->toBe(Operator::BETWEEN)
        ->and($views->value)->toBe([1_000.0, 5_000.0]);
});

it('resolves the field word before the comparator too', function () {
    $views = chipOn('views', 'views above 10k', $this->schema);

    expect($views->operator)->toBe(Operator::GT)
        ->and($views->value)->toBe(10_000.0);
});

it('scales percent fields from their unit', function () {
    $engagement = chipOn('engagement_rate', 'engagement above 8%', $this->schema);

    expect($engagement)->not->toBeNull()
        ->and($engagement->operator)->toBe(Operator::GT)
        ->and($engagement->value)->toBe(0.08);
});

it('reads enum values, their plurals, and negation', function (string $query, string $value, bool $negated) {
    $type = chipOn('type', $query, $this->schema);

    expect($type)->not->toBeNull()
        ->and($type->value)->toBe($value)
        ->and($type->operator)->toBe($negated ? Operator::NOT_EQUALS : Operator::EQUALS);
})->with([
    ['video', 'video', false],
    ['videos', 'video', false],
    ['podcasts', 'podcast', false],
    ['not articles', 'article', true],
    ['except podcasts', 'podcast', true],
]);

it('reads relation names from by and from', function () {
    $author = chipOn('author.name', 'from Ada Lovelace', $this->schema);

    expect($author->operator)->toBe(Operator::CONTAINS)
        ->and($author->value)->toBe('Ada Lovelace');
});

it('keeps multi-word names but drops trailing constraint words', function () {
    $result = chips('videos by Ada Lovelace above 10k views', $this->schema);

    expect($result->filters->whereField('author.name')->first()->value)->toBe('Ada Lovelace')
        ->and($result->filters->whereField('views')->first()->value)->toBe(10_000.0)
        ->and($result->leftover)->toBe('');
});

it('reads field-scoped syntax as explicit chips', function () {
    $result = chips('type: video, article author: Ada views: 10k', $this->schema);

    $type = $result->filters->whereField('type')->first();
    expect($type->explicit)->toBeTrue()
        ->and($type->operator)->toBe(Operator::IN)
        ->and($type->value)->toBe(['video', 'article']);

    expect($result->filters->whereField('author.name')->first())
        ->operator->toBe(Operator::CONTAINS)
        ->value->toBe('Ada')
        ->explicit->toBeTrue();

    expect($result->filters->whereField('views')->first())
        ->operator->toBe(Operator::EQUALS)
        ->value->toBe(10_000.0);
});

it('reads quoted phrases as explicit contains on the first searchable field', function () {
    $result = chips('title: "launch day" above 1k views', $this->schema);

    $title = $result->filters->whereField('title')->first();
    expect($title->explicit)->toBeTrue()
        ->and($title->operator)->toBe(Operator::CONTAINS)
        ->and($title->value)->toBe('launch day')
        ->and($result->leftover)->toBe('');
});

it('reads date expressions onto the default date field', function () {
    $result = chips('published yesterday', $this->schema);

    $date = $result->filters->whereField('published_at')->first();
    expect($date)->not->toBeNull()
        ->and($date->operator)->toBe(Operator::ON);
});

it('reads relative date ranges', function () {
    $date = chipOn('published_at', 'last 30 days', $this->schema);

    expect($date->operator)->toBe(Operator::BETWEEN)
        ->and($date->value[0]->diffInDays(now()))->toBeGreaterThan(29);
});

it('reads boolean vocabulary with negation', function () {
    expect(chipOn('is_featured', 'featured', $this->schema)->operator)->toBe(Operator::IS_TRUE)
        ->and(chipOn('is_featured', 'not featured', $this->schema)->operator)->toBe(Operator::IS_FALSE);
});

it('reads sort directives and consumes their words', function () {
    $result = chips('latest videos', $this->schema);

    expect($result->sort)->toBe(['field' => 'published_at', 'direction' => 'desc', 'label' => 'Latest'])
        ->and($result->leftover)->toBe('');
});

it('leaves unmatched words as leftover', function () {
    $result = chips('videos about rockets', $this->schema);

    expect($result->filters->whereField('type')->first())->not->toBeNull()
        ->and($result->leftover)->toBe('rockets');
});

it('does not invent a numeric field when the comparison is ambiguous', function () {
    $result = chips('above 5', $this->schema);

    expect($result->filters->whereField('views')->first())->toBeNull()
        ->and($result->filters->whereField('engagement_rate')->first())->toBeNull()
        ->and($result->leftover)->toBe('above 5');
});

it('matches field aliases and singular forms', function () {
    expect(chipOn('views', 'plays above 1k', $this->schema))->not->toBeNull()
        ->and(chipOn('engagement_rate', 'engagement rate above 4%', $this->schema))->not->toBeNull();
});

it('reads a between range', function () {
    $chip = chipOn('views', 'videos between 100 and 500 views', $this->schema);

    expect($chip->operator)->toBe('between')->and($chip->value)->toBe([100.0, 500.0]);
});

it('reads a bare year as a year', function () {
    $chip = chipOn('published_at', 'videos before 2026', $this->schema);

    expect($chip->operator)->toBe('before')->and($chip->value->toDateString())->toBe('2026-01-01');
});

it('reads last month as the previous calendar month', function () {
    $this->travelTo('2026-11-10 12:00:00');

    $chip = chipOn('published_at', 'podcasts from last month', $this->schema);

    expect($chip->operator)->toBe('between')
        ->and($chip->value[0]->toDateString())->toBe('2026-10-01')
        ->and($chip->value[1]->toDateString())->toBe('2026-10-31');
});

it('sorts by a field named in the past tense', function () {
    $result = chips('most viewed videos', $this->schema);

    expect($result->sort)->toMatchArray(['field' => 'views', 'direction' => 'desc']);
});

it('reads a direction word in front of a period', function () {
    $this->travelTo('2026-11-10 12:00:00');

    $after = chips('videos after last week', $this->schema);
    $since = chipOn('published_at', 'videos since last month', $this->schema);

    expect($after->filters->firstWhere('field', 'published_at')->operator)->toBe('after')
        ->and($after->filters->firstWhere('field', 'published_at')->value->toDateString())->toBe('2026-11-08')
        ->and($after->leftover)->toBe('')
        ->and($since->operator)->toBe('after')
        ->and($since->value->toDateString())->toBe('2026-10-01');
});

it('ends a date at the word that starts the topic', function () {
    $result = chips('videos since monday about rockets', $this->schema);

    expect($result->filters->firstWhere('field', 'published_at')->operator)->toBe('after')
        ->and($result->leftover)->toBe('rockets');
});

it('reads a bare month as the whole month', function () {
    $since = chipOn('published_at', 'videos since january', $this->schema);
    $after = chipOn('published_at', 'videos after march', $this->schema);
    $before = chipOn('published_at', 'videos before june 2025', $this->schema);

    expect($since->value->toDateString())->toBe('2026-01-01')
        ->and($after->value->toDateString())->toBe('2026-03-31')
        ->and($before->operator)->toBe('before')
        ->and($before->value->toDateString())->toBe('2025-06-01');
});
