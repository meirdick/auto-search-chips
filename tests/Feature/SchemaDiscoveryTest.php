<?php

use Meirdick\SearchChips\Discovery\SchemaDiscovery;
use Meirdick\SearchChips\Tests\Fixtures\AuditLog;
use Meirdick\SearchChips\Tests\Fixtures\Post;

beforeEach(function () {
    $this->discovery = app(SchemaDiscovery::class);
});

it('discovers field kinds from casts', function () {
    $schema = $this->discovery->discover(Post::class);

    expect($schema->field('type')->kind)->toBe('enum')
        ->and($schema->field('views')->kind)->toBe('number')
        ->and($schema->field('engagement_rate')->kind)->toBe('number')
        ->and($schema->field('is_featured')->kind)->toBe('boolean')
        ->and($schema->field('published_at')->kind)->toBe('datetime')
        ->and($schema->field('title')->kind)->toBe('text');
});

it('reads enum values from a backed-enum attribute', function () {
    expect($this->discovery->discover(Post::class)->field('type')->values)
        ->toBe(['video', 'article', 'podcast']);
});

it('applies attribute metadata: labels, units, scale, and aliases', function () {
    $engagement = $this->discovery->discover(Post::class)->field('engagement_rate');

    expect($engagement->label)->toBe('Engagement')
        ->and($engagement->unit)->toBe('percent')
        ->and($engagement->scale)->toBe(0.01)
        ->and($engagement->aliases)->toContain('engagement');
});

it('excludes secrets, hidden columns, and foreign keys', function () {
    $schema = $this->discovery->discover(Post::class);

    expect($schema->field('internal_note'))->toBeNull()
        ->and($schema->field('author_id'))->toBeNull()
        ->and($schema->field('password'))->toBeNull();
});

it('discovers relations as filterable name fields and honors method opt-outs', function () {
    $schema = $this->discovery->discover(Post::class);

    expect($schema->relations)->toHaveKey('author')
        ->and($schema->relations)->not->toHaveKey('internalAuthor')
        ->and($schema->field('author.name'))->not->toBeNull()
        ->and($schema->field('author.name')->relation)->toBe('author')
        ->and($schema->resolveFieldToken('author')->name)->toBe('author.name');
});

it('resolves searchable and orderable fields', function () {
    $schema = $this->discovery->discover(Post::class);

    expect($schema->searchable)->toBe(['title', 'body'])
        ->and($schema->orderable)->toContain('views')
        ->and($schema->orderable)->toContain('published_at');
});

it('lets config overrides exclude fields and add aliases', function () {
    $schema = $this->discovery->discover(Post::class, [
        'exclude' => ['body'],
        'fields' => ['title' => ['aliases' => ['headline']]],
        'relations' => ['author'],
    ]);

    expect($schema->field('body'))->toBeNull()
        ->and($schema->field('title')->aliases)->toContain('headline')
        ->and($schema->relations)->toHaveKey('author');
});

it('returns no fields for a model that opted out entirely', function () {
    expect($this->discovery->discover(AuditLog::class)->fields)->toBe([]);
});

it('versions the schema so caches invalidate when it changes', function () {
    $a = $this->discovery->discover(Post::class);
    $b = $this->discovery->discover(Post::class, ['exclude' => ['body']]);

    expect($a->version())->toHaveLength(8)
        ->and($a->version())->not->toBe($b->version());
});
