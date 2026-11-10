# Review of `meirdick/auto-search-chips` at `5b9812d`

Date: 2026-11-10. Scope: all of `src/`, `config/`, `routes/`, `resources/`, `docs/SPEC.md`.
Method: read the code, ran `vendor/bin/pest` locally (60 passed, 166 assertions).
No live model call was made. The demo app was not run.

## Outcome on branch `v0.1` (2026-11-10)

All 32 defects below are fixed on `v0.1`, with these exceptions:

- Item 6 (discovery exposes columns): discovery is unchanged for unregistered prototypes. The fix is the explicit `searchChips()` definition, the `only` option and `onlyWhen()`.
- Item 3: `$alias` is locked. `$chips` stays writable by the browser and is validated again on each action.
- Item 26: relations from traits and parent classes are found. A relation without a return type is still skipped.
- Item 25: `lower()` is removed and columns are qualified. PostgreSQL now uses `ILIKE`. Not run against MySQL or PostgreSQL yet.

From the design critique, not done: `Operator` and field kinds are still string constants. Scout is not built.
The merge policy changed after live tests: rules chips stand, the AI adds chips. See `TRIAL.md`.

## Verdict

- The spec is strong. The pipeline shape is right: schema, parser, normalizer, applicator.
- `FilterNormalizer` as the single trust boundary is the best part. Keep it.
- The code is a v0.1 core, not a public package yet.
- Three groups block a public release: unsafe defaults, a blocking AI call per keystroke, an unfinished frontend.
- The AI path has defects that the faked tests do not catch.

## Defects (fix before any release)

Security and cost:

1. Routes have no auth and no rate limit. `config/search-chips.php:67` sets `['web']` only. Spec §10.6 says `web` + `auth` + named limiter.
2. The Livewire component skips the policy. `authorizeSearch()` exists only in the HTTP controller.
3. Livewire `$alias` and `$chips` are public and not `#[Locked]`. A client can change `$alias` to any registered model.
4. Value sampling is on by default (`SchemaDiscovery.php:119`). It sends real row values to the AI provider. The config key `discovery.sample_values` is not in the config file. Spec §18.5 leans to off.
5. Sampling runs `SELECT DISTINCT ... LIMIT 8` on each text column, for example `body`. That is a table scan per column, per request.
6. Discovery exposes all columns that do not match a deny pattern. `email` is a relation name column (`SchemaDiscovery.php:41`). Filters then work as a probe for private values.
7. The parse cache is not wired. Each debounced keystroke is a paid AI call.

AI path:

8. System One boolean chips are always dropped. The parser returns `equals` (`SystemOneParser.php:175`). `Operator::forKind('boolean')` allows only `is_true` and `is_false`.
9. The operator heuristic reads the whole query, not the span. "under 5 minutes above 10k views" gives `lt` for both fields.
10. `RulesParser::inferOperator()` is the documented "shared oracle" for AI spans. Nothing calls it. Use it to fix item 9.
11. `inferredSpan()` returns the first number in the query for all fields (`SystemOneParser.php:166`).
12. The workers-ai transport sets `integer: true` for all number fields (`WorkersAiPackageExtractor.php:62`). "8.5%" breaks.
13. `ChatLlmParser` asks the model for byte offsets. Chat models count offsets badly. A wrong span drops the chip. Ask for the quoted substring and find the offset in PHP.
14. `ChatLlmParser` types `value` as a string. `between` and `in` cannot work.
15. `ChatLlmParser` has no test. Its `laravel/ai` calls are unverified.
16. The AI call blocks the request for up to 12 s. Spec §14 wants rules chips in under 50 ms, then an AI refine.

Frontend:

17. The Livewire view is an unstyled stub with no suggested-chip state. The Blade component has all the design.
18. `Search.php:45,55` has a no-op `->to(... ? null : null)`.
19. `clear()` dispatches a different payload shape (`alias, count, chips`) than `updatedQuery()` (`filters, leftover, count`). It also reports count 0, not the full count.
20. The Blade alias guard has a precedence bug (`! $x[$alias] ?? false`). An unknown alias throws "Undefined array key", not the helpful message.
21. The Blade component prints its `<script>` once per use. No `@once`, no published asset, no CSP nonce.
22. Tailwind 4 does not scan `vendor/`. The component is unstyled in a starter kit unless the app adds an `@source` line. Nothing documents this.
23. No test covers the Livewire component or the Blade component.

Performance and correctness:

24. Discovery runs schema queries on each request. `getColumnType()` runs per uncast column. No schema cache exists.
25. `lower(col) like ?` (`EloquentApplicator.php:139`) cannot use an index. Columns are not table-qualified, so joins break.
26. Relation discovery skips relations from traits and relations without a return type (`SchemaDiscovery.php:272`).
27. The config comment for `auto` (`config/search-chips.php:8`) contradicts the locked decision and the code.

Packaging:

28. `composer.lock` is committed. A library must not commit it.
29. `minimum-stability: dev`, missing `workbench/` paths in autoload-dev, `phpstan` plugin allowed but not installed.
30. No `LICENSE`, no `CHANGELOG.md`, no CI, no static analysis. README is 28 lines.
31. `livewire/livewire` and `laravel/ai` are not in `suggest`.
32. The controller imports `Illuminate\Foundation\...\AuthorizesRequests` and does not use it. `illuminate/foundation` is not a declared dependency.

## Design critique

- Names do not agree. Package `auto-search-chips`, namespace `SearchChips`, provider `AutoSearchChips...`, trait `HasAutoSearch`, scope `autoSearch`. Pick one stem.
- `parse()` returns `NormalizationResult`. That is an internal name on the public API.
- `Operator` and field kinds are string constants. PHP 8.3 is the floor, so use backed enums.
- Engines are closed. `engineChain()` uses `new` and a `match`. The `Parser` contract exists but a user cannot register an engine.
- The registry is config and facade only. A model cannot declare its own filters in code, except with class attributes.
- Discovery is both the convenience and the security policy. Those must be separate.
- The HTTP result payload uses `$model->only($columns)`. It ignores API resources and `$hidden` intent.
- Scope: the spec puts four frontends, two applicators and three engines in v0.1. That is too wide to finish well.

One note on a locked decision, stated once: System One as the default AI path depends on a preview model from one vendor.
`auto` falls back to rules without credentials, so the risk is contained. Keep the README lead on the rules engine.

## How I would build it

Order of work, smallest useful package first:

1. Rules engine as the product. No keys, no network, works on install. AI is an opt-in refine step.
2. Explicit definition as the production path, discovery as the generator.

   ```php
   class Post extends Model
   {
       use HasSearchChips;

       public function searchChips(ChipSchema $chips): void
       {
           $chips->text('title')->searchable();
           $chips->enum('type', PostType::class);
           $chips->number('views')->aliases('plays')->sortable();
           $chips->relation('author', 'name');
       }
   }
   ```

   `search-chips:schema posts --write` generates this from discovery. Models without the method use discovery in local only, or after an explicit opt-in.
3. One entry point that accepts a class or an alias: `SearchChips::for(Post::class)->search($q)`.
4. Engines as a `Manager` with drivers: `SearchChips::extend('my-engine', fn ($app) => ...)`. Engines resolve from the container.
5. Two-phase parse. Phase 1 returns rules chips at once. Phase 2 refines with AI and can only add or change chips that the user did not edit.
6. Safe HTTP defaults: `web` + `auth`, a named rate limiter, `viewAny` policy check, sampling off, schema cache (`search-chips:cache`, `search-chips:clear`).
7. One JSON contract and one headless TypeScript core. Each frontend is a thin view on it.
8. Frontend order by starter-kit use: Livewire, then React, then Vue, then Svelte. The Blade and Alpine component uses the same core.
9. Install command copies source into the app, in the shadcn manner: `search-chips:install --stack=livewire|react|vue|svelte`.
10. A `workbench/` demo app in the repo with seed data, started with `composer serve`. It replaces `/tmp/search-chips-demo`.
11. Scout applicator after the Eloquent path is stable.

Target usage:

```php
// Controller, any stack
return Inertia::render('Posts/Index', SearchChips::for(Post::class)->handle($request)->toProps());

// Builder
Post::query()->searchChips('videos by Ada above 10k views')->paginate();
```

```blade
<livewire:search-chips for="posts" />
```

## Path to a public release

Phase 0, correctness (private repo):

1. Fix defects 1 to 7 (safe defaults).
2. Fix defects 8 to 15 and add tests with recorded Clairic responses.
3. Fix defects 18 to 21 and add Livewire and Blade component tests.
4. Remove `composer.lock`, add `LICENSE`, Pint config, Larastan, GitHub Actions matrix (PHP 8.3 to 8.5, Laravel 12 and 13, SQLite, MySQL, PostgreSQL).

Phase 1, working product:

5. Add the `workbench/` demo and `composer serve`.
6. Add the schema cache and the parse cache.
7. Add the two-phase parse.
8. Verify the Cloudflare paths with live credentials (checklist in `STATUS.md`).
9. Finish the Livewire component: styled, keyboard flow, suggested chips, results slot.

Phase 2, reach:

10. Headless TypeScript core, React components, `search-chips:install`.
11. Vue and Svelte components.
12. Model-level `searchChips()` definition and `Manager` drivers.

Phase 3, launch:

13. README with a short demo recording, a 5-minute quick start per starter kit, config reference, security page.
14. Boost guideline file for agents.
15. Tag `v0.1.0`, publish on Packagist, make the repo public.
16. Submit to Laravel News, post the demo recording.

Release gate: the spec §17 success criteria 1, 2, 3, 5 and 7.
