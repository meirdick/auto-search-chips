# Auto Search Chips

Natural-language search that becomes removable filter chips over any Eloquent model.

A user types `videos by Ada above 10k views`. The text stays in the box as typed. Under the box, three chips show how it was read:
`Type is Video`, `Author contains Ada`, `Views greater than 10,000`. The list filters. Each chip can be removed.
An optional filter panel shows the same filters as controls: typing moves the controls, and a control changes the chips.

```php
Post::autoSearch('videos by Ada above 10k views')->paginate();
```

- Works with no AI and no API key. A deterministic rules engine reads the query in a few milliseconds.
- An AI engine can add the chips the rules missed: Cloudflare System One (Clairic), or any chat model through `laravel/ai`.
- UI components for Livewire, Blade + Alpine, and Inertia with React, Vue or Svelte.
- The AI never writes SQL. Every chip is checked against an allow-list of fields, operators and value types.

Requires PHP 8.3+ and Laravel 12 or 13.

> Status: 0.1, pre-release. The API can change before 1.0.

## Install

```bash
composer require meirdick/auto-search-chips
php artisan search-chips:install
```

The installer detects your stack. It publishes the config. For Inertia it copies the component source into `resources/js/components/search-chips/`. For Livewire and Blade it adds one `@source` line to `resources/css/app.css` so Tailwind 4 styles the package views. If that file has no Tailwind entry, the installer prints the line for you to add.

## Quick start

### 1. Say what is filterable

Add `searchChips()` to the model. Only these fields are reachable.

```php
use Meirdick\SearchChips\HasSearchChips;
use Meirdick\SearchChips\Schema\ChipSchema;

class Post extends Model
{
    use HasSearchChips;

    public function searchChips(ChipSchema $chips): void
    {
        $chips->text('title')->searchable();
        $chips->enum('type', PostType::class)->synonyms(['movie' => 'video', 'show' => 'podcast']);
        $chips->number('views')->aliases('plays')->sortable();
        $chips->boolean('is_featured')->label('Featured')->aliases('featured');
        $chips->datetime('published_at')->label('Published')->sortable();
        $chips->relation('author', 'name');
    }
}
```

No method? The package discovers the fields from the table and the casts. That is good for a prototype. Print a starting point with `php artisan search-chips:schema posts --stub`.

### 2. Register an alias

The UI and the JSON routes use an alias, never a class name.

```php
// AppServiceProvider::boot()
SearchChips::register(Post::class, 'posts');
```

### 3. Add the search box

**Livewire**

```blade
<livewire:search-chips for="posts" />
<livewire:search-chips for="posts" panel />   {{-- with the filter panel --}}
```

```php
#[On('search-chips-updated')]
public function filter(array $filters, string $leftover, ?array $sort): void
{
    [$this->filters, $this->leftover, $this->sort] = [$filters, $leftover, $sort];
}

public function render()
{
    return view('livewire.posts', [
        'posts' => SearchChips::for('posts')->applyChips($this->filters, $this->leftover, $this->sort)->paginate(),
    ]);
}
```

**Inertia (React, Vue, Svelte)**

```php
public function index(Request $request)
{
    $search = SearchChips::for('posts')->fromRequest($request);

    return Inertia::render('Posts/Index', [
        'posts' => $search->query()->with('author')->paginate()->withQueryString(),
        'searchChips' => $search->toProps(),
    ]);
}
```

```tsx
import { SearchChips } from '@/components/search-chips/search-chips';

<SearchChips for="posts" initial={searchChips} reload={['posts', 'searchChips']} />
<SearchChips for="posts" initial={searchChips} reload={['posts', 'searchChips']} panel />
```

The filter panel is in the Livewire and React components. Vue, Svelte and Blade have the search box and the chip row.

**Blade + Alpine**

```blade
<x-search-chips::search for="posts" />
```

Listen for the `search-chips:updated` browser event. Its `detail` holds `query`, `chips`, `leftover`, `sort` and `count`.

## PHP API

```php
$search = SearchChips::for(Post::class)->search('featured podcasts last month');   // alias or class

$search->filters();     // FilterCollection, the chips
$search->leftover();    // words that became a keyword search
$search->query();       // Eloquent builder with everything applied
$search->paginate();
$search->count();

SearchChips::for('posts')->applyChips($chips, $leftover, $sort);    // a chip set from the browser
SearchChips::for('posts')->parse($text, refine: false);            // rules only, no network
```

### What the rules engine reads

| Query | Chip |
| --- | --- |
| `videos`, `podcasts`, `movies` | enum values and their synonyms, singular or plural |
| `hopper` | a bare name matches the related name column and the text fields |
| `by Ada`, `author: Ada`, `not by Grace` | relation name |
| `above 10k views`, `fewer than 500 plays`, `between 100 and 500 views`, `more then 100` | number comparisons, `k` and `m` suffixes, `%`, the then/than typo |
| `last month`, `last 30 days`, `since monday`, `before 2026`, `in 2025` | dates |
| `featured`, `not featured` | booleans |
| `latest`, `oldest`, `most viewed` | sort |
| `type: video, article`, `"exact phrase"` | explicit syntax |
| every other word | keyword search on the `searchable()` fields |

## Engines

| `SEARCH_CHIPS_ENGINE` | Behavior |
| --- | --- |
| `auto` (default) | System One when Cloudflare credentials exist, else rules only |
| `rules` | rules only |
| `system-one` | Cloudflare Clairic |
| `ai` | a chat model through `laravel/ai`; set `SEARCH_CHIPS_CHAT_PROVIDER` and `SEARCH_CHIPS_CHAT_MODEL` |

```dotenv
CLOUDFLARE_ACCOUNT_ID=...
CLOUDFLARE_API_TOKEN=...
```

How the engines work together:

1. The rules engine answers first. The UI shows its chips at once.
2. The UI then asks for the AI reading. Rules chips always stand. The AI adds chips for words the rules did not explain.
3. The AI also names the topic words of the query. Leftover words outside that list ("after", "written") leave the keyword search.
4. An uncertain AI reading is a suggested chip. It shows with a dashed border and is not applied until the user accepts it.
5. If the AI call fails, the rules chips remain. There is no fallback from one AI engine to another.

With [`meirdick/laravel-cf-workersai`](https://github.com/meirdick/laravel-cf-workersai) 1.3+ installed and a `workers-ai` provider in `config/ai.php`, System One sends its request through that package (failover, AI Gateway). No keys are needed in this package's config then. Set `SEARCH_CHIPS_CF_TRANSPORT=direct` to skip it.

AI parses are cached for 5 minutes. With `auto`, no model is called during a test run.

Add your own engine:

```php
SearchChips::extend('mine', fn ($app) => new MyParser);   // implements Contracts\Parser
```

## Laravel Scout

A model that uses Scout's `Searchable` trait sends the keyword text of a query through Scout.

```php
SearchChips::for(Post::class)->search('videos about rockets above 10k views')->paginate();
// Scout: "rockets", with type = video and views > 10000 as engine filters.
```

When the engine can apply every chip and the sort, `get()`, `paginate()` and `count()` take their rows and totals from the engine. Otherwise they take up to `scout.limit` keys (1,000 by default) from Scout and filter them in the database. `query()` always uses the key path; it sends the chips the engine can apply with the keyword search, then applies every chip in SQL. `usesScoutFilters()` tells which path a search takes.

| Engine | Chips in the engine | Tested |
| --- | --- | --- |
| `collection`, `database` | all chips, and the `query` base scope | yes, SQLite |
| Meilisearch | `equals`, `not_equals`, `in`, `is_true`, `is_false`, `gt`, `gte`, `lt`, `lte`, `between`, `before`, `after`, on fields in `filterableAttributes` | yes, Meilisearch 1.56 |
| Typesense | the same operators, on fields in the collection schema | yes, Typesense 30.2 |
| Algolia and other engines | none: key path | no |

These chips always stay in the database: relation fields (`author.name`), fields with `onlyWhen()` conditions, text matching (`contains`, `starts_with`, `ends_with`), `is_empty`, `not_empty` and `on`. On Typesense a negated chip on an optional field also stays in the database, because Typesense has no "is not null" filter. On Meilisearch and Typesense a model with a `query` base scope uses the key path: the scope is SQL.

The index must hold the fields. Print the settings a model needs:

```bash
php artisan search-chips:scout posts
```

It prints `filterableAttributes` and `sortableAttributes` for `scout.meilisearch.index-settings`, and a `collection-schema` for `scout.typesense.model-settings`. It does not change your config. `toSearchableArray()` must return those fields, with dates as Unix timestamps and yes/no fields as booleans. On Meilisearch, a date chip stays in the database when `toSearchableArray()` returns the date as a string. Meilisearch counts at most 1,000 hits unless `pagination.maxTotalHits` is higher.

Keep every chip in the database with `scout.filters => false`. Turn the integration off with `scout.enabled`.

## Security

- Routes use `web`, `auth` and a rate limit (120 requests per minute) by default. Change `routes.middleware` for a public search page.
- A model is reachable from the browser only after `SearchChips::register()`.
- Authorize per model: `SearchChips::register(Post::class, 'posts', ['authorize' => 'viewAny'])`.
- Scope every search of a model: `['query' => fn ($query) => $query->where('team_id', auth()->user()->team_id)]`. Global scopes also apply.
- Protect a field with row conditions. An author filter then cannot find anonymous posts:

  ```php
  $chips->relation('user', 'name')->onlyWhen(['is_anonymous' => false]);
  ```

- The typed sentence and the field list go to the AI engine. No row data goes, unless you turn on value sampling (`discovery.sample_values`, off by default).
- Zero-config discovery skips hidden attributes, foreign keys, the soft-delete column, and columns that look like secrets. Declare `searchChips()` on any model that users can search.

## Production

```dotenv
SEARCH_CHIPS_SCHEMA_CACHE=true
```

```bash
php artisan search-chips:cache    # on deploy
php artisan search-chips:clear
```

A model that declares `searchChips()` needs no schema query at all.

## Testing

```php
SearchChips::fake()->shouldReturn('posts', $result);
SearchChips::assertParsed('videos by Ada');
```

## Commands

| Command | Purpose |
| --- | --- |
| `search-chips:install {--stack=} {--force}` | config and UI component for your stack |
| `search-chips:schema {alias} {--stub} {--json}` | show what is filterable |
| `search-chips:parse {alias} {query}` | show the chips of a query |
| `search-chips:cache`, `search-chips:clear` | schema and parse caches |

## Demo

```bash
git clone https://github.com/meirdick/auto-search-chips && cd auto-search-chips
composer install && composer serve
```

Open `http://127.0.0.1:8000`. For live AI parsing, start it with `CLOUDFLARE_ACCOUNT_ID` and `CLOUDFLARE_API_TOKEN` set.

A hosted copy of this demo app runs in a Cloudflare Container. Try it at [meirdick.com/lab/search-chips](https://meirdick.com/lab/search-chips/). Its code is in `demo/`.

## Not in 0.1

Chip filters on Algolia (keyword text only), the filter panel for Vue, Svelte and Blade, OR groups, and an accuracy test set for the AI engines.

## Credits

Shout-out to Pratik (@maybepratikk) for the visual idea. MIT license.
