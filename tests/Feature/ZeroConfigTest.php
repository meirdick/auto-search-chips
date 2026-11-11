<?php

use Meirdick\SearchChips\Discovery\SchemaDiscovery;
use Meirdick\SearchChips\Facades\SearchChips;
use Meirdick\SearchChips\Tests\Fixtures\Author;
use Meirdick\SearchChips\Tests\Fixtures\PlainPost;

// A model with the trait and nothing else. The package must find the fields.
beforeEach(function () {
    config()->set('search-chips.engine', 'rules');
    config()->set('search-chips.discovery.sample_values', true);

    $ada = Author::create(['name' => 'Ada Lovelace']);

    foreach (range(1, 12) as $i) {
        PlainPost::create([
            'author_id' => $ada->id,
            'type' => ['video', 'article', 'podcast'][$i % 3],
            'title' => "Post number {$i}",
            'views' => $i * 1000,
            'engagement_rate' => $i / 100,
            'is_featured' => $i === 3,
            'published_at' => now()->subDays($i),
        ]);
    }

    SearchChips::register(PlainPost::class, 'plain');
    $this->schema = app(SchemaDiscovery::class)->discover(PlainPost::class);
});

it('reads the values of a short text column, and not of a free text column', function () {
    expect($this->schema->field('type')->values)->toEqualCanonicalizing(['video', 'article', 'podcast'])
        ->and($this->schema->field('title')->values)->toBeNull();
});

it('writes short labels for flag and date columns', function () {
    expect($this->schema->field('is_featured')->displayLabel())->toBe('Featured')
        ->and($this->schema->field('published_at')->displayLabel())->toBe('Published')
        ->and($this->schema->field('views')->displayLabel())->toBe('Views');
});

it('reads a rate column as a percent', function () {
    $rate = $this->schema->field('engagement_rate');

    expect($rate->unit)->toBe('percent')
        ->and($rate->scale)->toBe(0.01)
        ->and(SearchChips::for('plain')->search('engagement above 8%')->count())->toBe(4);
});

it('finds chips with no field list', function () {
    $search = SearchChips::for('plain')->search('featured videos by Ada above 2k views');

    expect($search->filters()->pluck('label')->all())
        ->toEqualCanonicalizing(['Type is Video', 'Featured is yes', 'Author contains Ada', 'Views greater than 2,000'])
        ->and($search->leftover())->toBe('')
        ->and($search->count())->toBe(1);
});

it('sorts on a number column', function () {
    $search = SearchChips::for('plain')->search('most viewed podcasts');

    expect($search->sort())->toMatchArray(['field' => 'views', 'direction' => 'desc'])
        ->and($search->get()->first()->views)->toBe(11000);
});

it('drops a value that is not in the value list', function () {
    $result = SearchChips::for('plain')->applyChips([
        ['id' => 'a', 'field' => 'type', 'operator' => 'equals', 'value' => 'movies'],
        ['id' => 'b', 'field' => 'type', 'operator' => 'equals', 'value' => 'videos'],
    ]);

    expect($result->filters()->pluck('value')->all())->toBe(['video']);
});
