## Auto Search Chips

This package turns a natural-language search box into filter chips over an Eloquent model.

- Declare filterable fields on the model with `searchChips(ChipSchema $chips)`. Do not rely on discovery for a model that users search.
- Register an alias in a service provider: `SearchChips::register(Post::class, 'posts')`. The UI and routes take the alias, never a class name.
- Build queries with `SearchChips::for('posts')->fromRequest($request)` in a controller, or `->applyChips($filters, $leftover, $sort)` in a Livewire component. Then call `->query()`, `->paginate()` or `->count()`.
- Use `Post::autoSearch($text)` for code-level search. It needs the `HasSearchChips` trait.
- Scope a model's searches with the `query` option and guard them with the `authorize` option of `register()`.
- Protect a field that must not reveal hidden rows with `->onlyWhen(['column' => value])`.
- UI: `<livewire:search-chips for="posts" />`, `<x-search-chips::search for="posts" />`, or the `SearchChips` component that `php artisan search-chips:install` copies into `resources/js/components/search-chips/`.
- In tests, use `SearchChips::fake()` or set `search-chips.engine` to `rules`. Never call a live model from a test.
- Inspect with `php artisan search-chips:schema {alias}` and `php artisan search-chips:parse {alias} {query}`.

@verbatim
<code-snippet name="Model definition" lang="php">
public function searchChips(ChipSchema $chips): void
{
    $chips->text('title')->searchable();
    $chips->enum('status', OrderStatus::class);
    $chips->number('total')->aliases('amount')->sortable();
    $chips->datetime('created_at')->label('Created')->sortable();
    $chips->relation('customer', 'name');
}
</code-snippet>
@endverbatim
