# Status — `meirdick/auto-search-chips`

> Maintainer working notes: what is built, what is verified, and what still needs
> verification before v0.1 is publishable. Design rationale, UX spec, and the full
> roadmap live in the spec artifact (*Auto Search Chips — Laravel Package Spec v0.2*),
> not here. Update this file as the work moves.

**Last updated:** 2026-11-10

---

## Delivery state

- `v0.1` is on GitHub (`meirdick/auto-search-chips`, private). Scout engine work is in PR #1 from `scout-engines`.
- Not on Packagist. CI (`.github/workflows/tests.yml`) ran on PR #1; all jobs passed.

## Decisions locked in

- Package `meirdick/auto-search-chips`, namespace `Meirdick\SearchChips`.
- System One (Cloudflare Clairic) is the default AI path. No automatic fallback from one AI engine to another.
- `meirdick/laravel-cf-workersai` is an optional integration, never a dependency.
- Routes and components accept registered aliases only.
- Credit line for Pratik (@maybepratikk) stays one line in the README.
- New on 2026-11-10: rules chips always stand; the AI adds chips for words the rules did not explain.
- New on 2026-11-10: the search box holds only the typed text. Chips show under it. A filter panel mirrors them.
- New on 2026-11-10: Scout stays optional. A `Searchable` model uses it for keyword text with no setup.
- New on 2026-11-10: chips go to the Scout engine as filters only when it can apply every chip and the sort. Otherwise the key path (up to `scout.limit` keys, then SQL) stays. A chip is never dropped.
- Plan after v0.1.0: publish the workbench demo as a working lab on meirdick.com.

## Verified on 2026-11-10

| Check | Result |
| --- | --- |
| `vendor/bin/pest` | 246 pass with Meilisearch and Typesense running; 188 pass and 58 skip without them. SQLite |
| `vendor/bin/phpstan analyse` (level 5) | no errors |
| `vendor/bin/pint --test` | passes |
| `npm test` (vitest, headless core) | 7 pass |
| `npm run typecheck` | passes |
| Workbench demo, Livewire and Blade, in a browser | chips, remove, results work |
| Live Clairic through the direct REST transport | works; CLI parse with PHP boot about 0.75 s |
| Real app trial (private app, Inertia React) | works, see `TRIAL.md` |
| Live Clairic through `meirdick/laravel-cf-workersai` 1.3.0, no direct keys | works (CLI parse in the workbench) |
| GitHub Actions on PR #1 (commit 14cace2) | all 9 jobs pass: PHP 8.3, 8.4, 8.5 x Laravel 12, 13; `quality`; `js`; `scout` with Meilisearch and Typesense service containers and `--fail-on-skipped` |
| Scout, `collection` and `database` engines | `tests/Feature/ScoutEnginesTest.php`, SQLite |
| Scout, Meilisearch 1.56.2 (binary in an orb) and `getmeili/meilisearch:v1.56` (Docker in an orb) | same tests pass |
| Scout, Typesense 30.2 (binary in an orb) and `typesense/typesense:30.2` (Docker in an orb) | same tests pass |
| Engine filters: each operator, negations, rows with null fields, 1,500 rows (1,120 matches, paged), mixed engine and SQL chips, `query` base scope, `authorize` | pass on all four engines |
| `search-chips:scout {alias}` | output checked by a test; the Meilisearch and Typesense settings it prints are the ones the engine tests use |

## Not verified

- Vue and Svelte components: written, never compiled or run.
- Scout with Algolia: not run. It has no local server. Chips stay on the key path there.
- Scout `database` engine on MySQL or PostgreSQL (full-text, trigram): not run; SQLite only.
- Meilisearch and Typesense on other versions than 1.56 and 30.2; `typesense/typesense-php` 4.x (local runs use 5.2.0).
- Scout 10: the code reads `wheres`, `whereIns` and `whereNotIns` and checks for a taken field; local runs use Scout 11.10.0 only.
- Soft-deleted Scout models with engine filters.
- Chat engine (`engine: ai`): tested with the `laravel/ai` fake only, no live provider.
- MySQL and PostgreSQL: tests run on SQLite only.
- Meilisearch and Typesense on Laravel 12 or PHP 8.3/8.5: the `scout` job runs PHP 8.4 and Laravel 13 only. The matrix jobs skip the engine tests.
- Livewire 3: local runs use Livewire 4.
- `search-chips:install` for the Livewire stack in a real starter kit (unit test only).
- Accessibility: labels and a live region exist; no screen reader or axe run.

## Not built

- Engine-side chip filters on Algolia.
- Filter panel for the Vue, Svelte and Blade components.
- Clef decision routing (operators come from the rules engine's reading of each span).
- Chip editing popover, OR groups, facet counts.
- `search-chips:eval` and a golden query set.
- Backed enums for `Operator` and field kinds.

## Dev loop

```bash
composer install && npm install
vendor/bin/pest --compact
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/pint
npm test && npm run typecheck
npm run build        # after a change in resources/js: rebuilds dist/search-chips.js
composer serve       # workbench demo at http://127.0.0.1:8000 (Livewire) and /blade
```

Live AI in the demo: start it with `CLOUDFLARE_ACCOUNT_ID` and `CLOUDFLARE_API_TOKEN` in the shell.

Real-app trial: a private app, in a git worktree on a trial branch.
It uses this folder through a composer path repository, so package edits apply at once.
After a change to `resources/js/core.ts` or `stubs/`, run `php artisan search-chips:install --force` and `npm run build` there.

## Before tagging v0.1.0

1. Create the GitHub repo, commit, push, let CI run the Laravel 12/13 and PHP 8.3 to 8.5 matrix.
2. Compile and run the Vue and Svelte components in a starter kit.
3. Run the suite on MySQL and PostgreSQL.
4. Run the Livewire installer in a fresh Livewire starter kit.
5. Record the demo for the README.
