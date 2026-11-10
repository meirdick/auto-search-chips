<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Meirdick\SearchChips\Facades\SearchChips;
use Meirdick\SearchChips\Tests\Fixtures\IndexedPost;
use Meirdick\SearchChips\Tests\Support\ScoutEngines;

/*
 * Every test runs on the collection and database engines, and on
 * Meilisearch and Typesense when MEILISEARCH_HOST / TYPESENSE_HOST point at
 * a running server. Expected rows come from the SCOUT_ROWS table below, not from
 * the package.
 */

const SCOUT_ROWS = [
    ['id' => 1, 'title' => 'Rockets one', 'type' => 'video', 'views' => 50_000, 'is_featured' => false, 'published_at' => '2025-01-10 12:00:00', 'author_id' => 1, 'body' => 'launch', 'engagement_rate' => 0.01, 'internal_note' => 'x'],
    ['id' => 2, 'title' => 'Rockets two', 'type' => 'article', 'views' => 20, 'is_featured' => true, 'published_at' => '2025-03-01 00:00:00', 'author_id' => 2, 'body' => 'launch', 'engagement_rate' => 0.2, 'internal_note' => 'x'],
    ['id' => 3, 'title' => 'Rockets three', 'type' => 'podcast', 'views' => 900, 'is_featured' => false, 'published_at' => null, 'author_id' => null, 'body' => null, 'engagement_rate' => 0.05, 'internal_note' => null],
    ['id' => 4, 'title' => 'Rockets four', 'type' => 'video', 'views' => 12_000, 'is_featured' => true, 'published_at' => '2024-11-05 08:00:00', 'author_id' => 1, 'body' => 'orbit', 'engagement_rate' => 0.08, 'internal_note' => 'x'],
    ['id' => 5, 'title' => 'Rockets five', 'type' => 'video', 'views' => 300, 'is_featured' => false, 'published_at' => '2025-06-20 09:30:00', 'author_id' => 2, 'body' => 'orbit', 'engagement_rate' => 0.12, 'internal_note' => 'y'],
    ['id' => 6, 'title' => 'Garden notes', 'type' => 'video', 'views' => 90_000, 'is_featured' => false, 'published_at' => '2025-01-10 12:00:00', 'author_id' => 1, 'body' => 'launch', 'engagement_rate' => 0.3, 'internal_note' => 'x'],
    ['id' => 7, 'title' => 'Rockets seven', 'type' => 'article', 'views' => 5_000, 'is_featured' => false, 'published_at' => '2023-02-02 10:00:00', 'author_id' => 1, 'body' => 'launch', 'engagement_rate' => 0.09, 'internal_note' => 'x'],
    ['id' => 8, 'title' => 'Rockets eight', 'type' => 'podcast', 'views' => 10_000, 'is_featured' => true, 'published_at' => '2025-02-01 00:00:00', 'author_id' => null, 'body' => 'launch', 'engagement_rate' => 0.15, 'internal_note' => 'x'],
];

const SCOUT_AUTHORS = [1 => 'Ada', 2 => 'Grace'];

function useScoutEngine(string $engine): void
{
    if (($reason = ScoutEngines::use($engine)) !== null) {
        test()->markTestSkipped($reason);
    }

    SearchChips::register(IndexedPost::class, 'indexed');
}

function seedScoutRows(): void
{
    DB::table('authors')->insert(array_map(fn ($id, $name) => ['id' => $id, 'name' => $name], array_keys(SCOUT_AUTHORS), SCOUT_AUTHORS));

    ScoutEngines::seed(SCOUT_ROWS);
}

/** Ids of the rows whose title has "rockets" and that pass the check. */
function scoutIds(Closure $check): array
{
    return collect(SCOUT_ROWS)
        ->filter(fn ($row) => str_contains($row['title'], 'Rockets') && $check($row))
        ->pluck('id')->sort()->values()->all();
}

function at(?string $value): ?Carbon
{
    return $value === null ? null : Carbon::parse($value);
}

afterEach(fn () => ScoutEngines::flush());

dataset('scout engines', ScoutEngines::ALL);

dataset('index chips', [
    'equals' => [['field' => 'type', 'operator' => 'equals', 'value' => 'video'], fn ($r) => $r['type'] === 'video'],
    'not_equals' => [['field' => 'type', 'operator' => 'not_equals', 'value' => 'video'], fn ($r) => $r['type'] !== 'video'],
    'in' => [['field' => 'type', 'operator' => 'in', 'value' => ['video', 'podcast']], fn ($r) => in_array($r['type'], ['video', 'podcast'], true)],
    'negated in' => [['field' => 'type', 'operator' => 'in', 'value' => ['video'], 'negated' => true], fn ($r) => $r['type'] !== 'video'],
    'in, values with spaces' => [['field' => 'title', 'operator' => 'in', 'value' => ['Rockets one', 'Rockets four']], fn ($r) => in_array($r['title'], ['Rockets one', 'Rockets four'], true)],
    'is_true' => [['field' => 'is_featured', 'operator' => 'is_true'], fn ($r) => $r['is_featured']],
    'is_false' => [['field' => 'is_featured', 'operator' => 'is_false'], fn ($r) => ! $r['is_featured']],
    'gt' => [['field' => 'views', 'operator' => 'gt', 'value' => 900], fn ($r) => $r['views'] > 900],
    'gte' => [['field' => 'views', 'operator' => 'gte', 'value' => 900], fn ($r) => $r['views'] >= 900],
    'lt' => [['field' => 'views', 'operator' => 'lt', 'value' => 900], fn ($r) => $r['views'] < 900],
    'lte' => [['field' => 'views', 'operator' => 'lte', 'value' => 900], fn ($r) => $r['views'] <= 900],
    'between' => [['field' => 'views', 'operator' => 'between', 'value' => [300, 10_000]], fn ($r) => $r['views'] >= 300 && $r['views'] <= 10_000],
    'negated gt' => [['field' => 'views', 'operator' => 'gt', 'value' => 900, 'negated' => true], fn ($r) => $r['views'] <= 900],
    'negated between' => [['field' => 'views', 'operator' => 'between', 'value' => [300, 10_000], 'negated' => true], fn ($r) => $r['views'] < 300 || $r['views'] > 10_000],
    'negated is_true' => [['field' => 'is_featured', 'operator' => 'is_true', 'negated' => true], fn ($r) => ! $r['is_featured']],
    'before' => [['field' => 'published_at', 'operator' => 'before', 'value' => '2025-01-10'], fn ($r) => $r['published_at'] !== null && at($r['published_at'])->lt('2025-01-10')],
    'after' => [['field' => 'published_at', 'operator' => 'after', 'value' => '2025-01-10'], fn ($r) => $r['published_at'] !== null && at($r['published_at'])->gt('2025-01-10')],
    'between dates, ends included' => [['field' => 'published_at', 'operator' => 'between', 'value' => ['2025-01-01', '2025-03-01']], fn ($r) => $r['published_at'] !== null && at($r['published_at'])->between('2025-01-01', '2025-03-01')],
]);

it('applies a chip in the search engine', function (string $engine, array $chip, Closure $check) {
    useScoutEngine($engine);
    seedScoutRows();

    $expected = scoutIds($check);
    $search = SearchChips::for('indexed')->applyChips([$chip], 'rockets');

    expect($search->filters())->toHaveCount(1)
        ->and($search->usesScoutFilters())->toBeTrue()
        ->and($search->get()->pluck('id')->sort()->values()->all())->toBe($expected)
        ->and($search->count())->toBe(count($expected))
        ->and($search->paginate(2)->total())->toBe(count($expected))
        ->and($search->query()->pluck('id')->sort()->values()->all())->toBe($expected);
})->with('scout engines')->with('index chips');

dataset('null-aware negations', [
    // published_at is null on row 3. SQL leaves that row out of every
    // comparison, negated or not, so the engine must too.
    'negated after' => [['field' => 'published_at', 'operator' => 'after', 'value' => '2025-01-10', 'negated' => true], fn ($r) => $r['published_at'] !== null && at($r['published_at'])->lte('2025-01-10')],
    'not_equals on a nullable field' => [['field' => 'body', 'operator' => 'not_equals', 'value' => 'launch'], fn ($r) => $r['body'] !== null && $r['body'] !== 'launch'],
]);

it('leaves rows with an empty field out of a negated chip', function (string $engine, array $chip, Closure $check) {
    useScoutEngine($engine);
    seedScoutRows();

    $expected = scoutIds($check);
    $search = SearchChips::for('indexed')->applyChips([$chip], 'rockets');

    // Typesense has no "is not null" filter, so an optional field stays in the database.
    expect($search->usesScoutFilters())->toBe($engine !== 'typesense')
        ->and($search->get()->pluck('id')->sort()->values()->all())->toBe($expected)
        ->and($search->count())->toBe(count($expected));
})->with('scout engines')->with('null-aware negations');

dataset('database chips', [
    'relation field' => [['field' => 'author.name', 'operator' => 'contains', 'value' => 'Ada'], fn ($r) => $r['author_id'] === 1],
    'field with onlyWhen() conditions' => [['field' => 'internal_note', 'operator' => 'equals', 'value' => 'x'], fn ($r) => $r['internal_note'] === 'x' && ! $r['is_featured']],
    'negated text contains' => [['field' => 'title', 'operator' => 'contains', 'value' => 'one', 'negated' => true], fn ($r) => ! str_contains($r['title'], 'one')],
    // A percent field: 8.5 means 0.085.
    'field not in the index settings' => [['field' => 'engagement_rate', 'operator' => 'gt', 'value' => 8.5], fn ($r) => $r['engagement_rate'] > 0.085],
]);

it('keeps a chip the index cannot apply in the database', function (string $engine, array $chip, Closure $check) {
    useScoutEngine($engine);
    seedScoutRows();

    $expected = scoutIds($check);
    $search = SearchChips::for('indexed')->applyChips([$chip], 'rockets');

    // The collection and database engines run every chip as SQL.
    expect($search->usesScoutFilters())->toBe(in_array($engine, ['collection', 'database'], true))
        ->and($search->get()->pluck('id')->sort()->values()->all())->toBe($expected)
        ->and($search->count())->toBe(count($expected))
        ->and($search->paginate()->total())->toBe(count($expected));
})->with('scout engines')->with('database chips');

it('filters a mixed query in the engine and the database', function (string $engine) {
    useScoutEngine($engine);
    seedScoutRows();

    $search = SearchChips::for('indexed')->applyChips([
        ['field' => 'type', 'operator' => 'equals', 'value' => 'video'],
        ['field' => 'author.name', 'operator' => 'contains', 'value' => 'Ada'],
    ], 'rockets');

    expect($search->usesScoutFilters())->toBe(in_array($engine, ['collection', 'database'], true))
        ->and($search->get()->pluck('id')->sort()->values()->all())->toBe([1, 4])
        ->and($search->count())->toBe(2)
        ->and($search->paginate()->total())->toBe(2);
})->with('scout engines');

it('applies the base query scope', function (string $engine) {
    useScoutEngine($engine);
    seedScoutRows();

    SearchChips::register(IndexedPost::class, 'indexed', ['query' => fn ($query) => $query->where('views', '<', 10_000)]);

    $search = SearchChips::for('indexed')->applyChips([['field' => 'type', 'operator' => 'equals', 'value' => 'video']], 'rockets');

    // A scope is SQL: only the collection and database engines can run it.
    expect($search->usesScoutFilters())->toBe(in_array($engine, ['collection', 'database'], true))
        ->and($search->get()->pluck('id')->all())->toBe([5])
        ->and($search->count())->toBe(1)
        ->and($search->paginate()->total())->toBe(1);
})->with('scout engines');

it('sorts in the engine', function (string $engine) {
    useScoutEngine($engine);
    seedScoutRows();

    $search = SearchChips::for('indexed')->applyChips(
        [['field' => 'type', 'operator' => 'equals', 'value' => 'video']],
        'rockets',
        ['field' => 'views', 'direction' => 'asc'],
    );

    expect($search->usesScoutFilters())->toBeTrue()
        ->and($search->get()->pluck('id')->all())->toBe([5, 4, 1])
        ->and($search->paginate(2, page: 2)->pluck('id')->all())->toBe([1])
        ->and($search->paginate(2)->url(2))->not->toContain('query=');
})->with('scout engines');

it('pages and counts more than 1,000 matches', function (string $engine) {
    useScoutEngine($engine);

    ScoutEngines::seed(array_map(fn ($i) => [
        'id' => $i,
        'title' => "Rockets {$i}",
        'type' => $i % 5 === 0 ? 'article' : 'video',
        'views' => $i,
    ], range(1, 1_500)));

    // Videos about rockets above 100 views: ids 101 to 1,500, less every fifth.
    $expected = count(array_filter(range(101, 1_500), fn ($i) => $i % 5 !== 0));
    $chips = [
        ['field' => 'type', 'operator' => 'equals', 'value' => 'video'],
        ['field' => 'views', 'operator' => 'gt', 'value' => 100],
    ];

    $search = SearchChips::for('indexed')->applyChips($chips, 'rockets');
    $last = $search->paginate(50, page: 23);

    expect($expected)->toBe(1_120)
        ->and($search->usesScoutFilters())->toBeTrue()
        ->and($search->count())->toBe($expected)
        ->and($last->total())->toBe($expected)
        ->and($last->lastPage())->toBe(23)
        ->and($last->count())->toBe(20)
        ->and($last->getCollection()->every(fn ($post) => $post->type === 'video' && $post->views > 100))->toBeTrue();

    // The key path takes at most 1,000 keys from Scout.
    config()->set('search-chips.scout.filters', false);

    $capped = SearchChips::for('indexed')->applyChips($chips, 'rockets');

    expect($capped->usesScoutFilters())->toBeFalse()
        ->and($capped->paginate(50)->total())->toBeLessThan($expected);
})->with('scout engines');

it('can keep every chip in the database', function (string $engine) {
    useScoutEngine($engine);
    seedScoutRows();
    config()->set('search-chips.scout.filters', false);

    $search = SearchChips::for('indexed')->applyChips([['field' => 'type', 'operator' => 'equals', 'value' => 'video']], 'rockets');

    expect($search->usesScoutFilters())->toBeFalse()
        ->and($search->get()->pluck('id')->sort()->values()->all())->toBe([1, 4, 5])
        ->and($search->paginate()->total())->toBe(3);
})->with('scout engines');
