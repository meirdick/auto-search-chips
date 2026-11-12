# Changelog

## 0.1.0 (unreleased)

First public version.

- Rules engine: enum values, relation names, number comparisons, dates, booleans, sort words, `field: value` syntax, quoted phrases.
- AI engines: Cloudflare System One (Clairic) and chat models through `laravel/ai`. Custom engines with `SearchChips::extend()`.
- Two-phase parse: rules chips at once, AI refine after. Suggested chips for uncertain AI readings.
- `searchChips(ChipSchema $chips)` model definition, zero-config discovery, `search-chips:schema --stub`.
- `SearchChips::for()`, `autoSearch()` scope, `fromRequest()`, `toProps()`, `applyChips()`.
- Components: Livewire, Blade + Alpine, React, Vue, Svelte, on one headless TypeScript core. The box holds the typed text; chips, keyword text and sort show under it.
- Filter panel (Livewire, React): the chips as controls, in sync with the search box.
- Value synonyms, keyword search over related names, `onlyWhen()` row conditions.
- Value matching: with Clairic, a Clef decision model maps a word such as "movies" to the allowed value `video`.
- Zero-config discovery: short labels for flag and date columns, sort on number columns, percent on rate columns, value lists only for columns with 2 to 8 values.
- Laravel Scout: keyword text through Scout for `Searchable` models.
- Laravel Scout: chips go to the engine as filters when it can apply them all (`collection`, `database`, Meilisearch, Typesense). Pages and totals then come from the engine, with no 1,000-key cap. Other chips keep the key path. Config `scout.filters`.
- `search-chips:scout {alias}`: prints the Meilisearch index settings and Typesense collection schema a model needs.
- Tests for Scout on the `collection`, `database`, Meilisearch and Typesense engines. A CI job runs Meilisearch and Typesense as service containers.
- `meirdick/laravel-cf-workersai` 1.3+ as the System One transport when installed.
- JSON routes with auth, rate limit, per-model authorization and base query.
- Schema cache, AI parse cache, `SearchChips::fake()`.
