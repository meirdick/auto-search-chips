# Trial in a real app

Date: 2026-11-10. App: Laravel 13.11, Inertia 2 + React 19, Tailwind 4, shadcn.
Model: `CommunityPost` on `/community`. Engine: live Cloudflare Clairic.
Location: a private app, in a git worktree on a trial branch, with a private SQLite file.

## Steps a developer takes

1. `composer require meirdick/auto-search-chips`
2. `php artisan search-chips:install` (detected `react`, copied 3 files)
3. `php artisan search-chips:schema community-posts --stub`, paste into the model, trim
4. `SearchChips::register(...)` in `AppServiceProvider`
5. Controller: replace the `LIKE` block with `SearchChips::for(...)->fromRequest($request)`
6. Page: replace the search `<form>` with `<SearchChips ... />`

App diff: 4 files, 56 lines added, 36 removed. Component source: 3 copied files. Tests: 1 new file.
The copied React component passed the app's `tsc` and took the app's theme without edits.

## Defects the trial found (all fixed in the package)

1. An AI chip was dropped when the chip set came back from the browser. Page reload lost the "Author" chip.
2. AI-first merge threw away correct rules chips. Now rules chips stand and the AI adds chips.
3. Clairic returned a "Title contains <whole query>" chip. Free-text fields are no longer sent to it.
4. "not by Grace" lost its negation on the AI path.
5. Comparator and field words ("above", "views") stayed in the keyword search and emptied the results.
6. A suggested chip removed its words from the keyword search, so results widened.
7. Filler words ("written", "with") in the leftover emptied the results. A stopword list now strips them.
8. `between 100 and 500` crashed the rules engine.
9. `before 2026` was read as the time 20:26 today.
10. `last month` was not understood.
11. `most viewed` did not sort. The sort rule read one character of the field name.
12. Labels: "Type is videos", "User contains Ada", "Views greater than 10000".
13. The page URL carried the full chip payload (about 1,700 characters). Now about 560.
14. Zero-config discovery exposed `deleted_at`, and `user.name` for anonymous posts. Added `onlyWhen()`.
15. With `auto`, the app's test run called the live model. `auto` now uses rules only in tests.
16. The schema table printed an empty "Searchable" column.

## Second round, from use of the demo

17. Chips inside the search box sat next to the typed text and read as a duplicate. The box now holds only the text; chips show under it.
18. The copied input showed a second focus box in the app, from the app's global `:focus-visible` rule. The input now sets its outline inline.
19. `hopper` found nothing: a bare name was only searched in the title. Related names now join the keyword search.
20. `more then 100` was read as "is 100" plus the keyword "then". The typo is now read as "than".
21. `movies` was not read as type video. Fields now take synonyms.
22. Added the filter panel (Livewire, React): the same filters as controls.

23. `after last week` was read as the week itself, and "after" became a keyword. A direction word now turns a period into one bound.
24. Glue words in the keyword text: Clairic now names the topic words of the query, and other leftover words are dropped. It never adds a word.

## Friction that remains

- The result count comes from the JSON route. It ignores page-level filters (category tabs), so it can differ from the list.
- A page that has other filters must keep `q`, `chips` and `sort` in its own links. The trial needed 2 extra lines.
- Old links with `?search=` needed one line in the controller.
- "in diet" does not match the category "Diet & Nutrition". Relation values are unknown to the engines unless sampling is on.
- Clairic returns words from the query. Synonyms come from the field's `synonyms()`, not from the model.
- The app has `meirdick/laravel-cf-workersai` 0.2.0, which has no Clairic support. The trial uses the direct transport. The package transport was verified in the workbench with 1.3.0.
- On this data the rules engine made most chips. Clairic added the author chip. An accuracy test set is needed to tune this.
- `composer require` failed first on a missing `ext-sockets` (the app's Pest browser plugin, not this package).

## Measured

- CLI parse with a live Clairic call, PHP boot included: about 0.75 s. The call alone was not timed.
- Cost per parse: about 670 input tokens (Cloudflare usage field).
- App tests: 5 new tests pass. The 30 existing community tests pass.
