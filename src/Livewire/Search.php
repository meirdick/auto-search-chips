<?php

namespace Meirdick\SearchChips\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Meirdick\SearchChips\Data\ChipSet;
use Meirdick\SearchChips\PendingSearch;
use Meirdick\SearchChips\SearchChipsManager;

/**
 * <livewire:search-chips for="posts" />
 *
 * Renders the search box and its chips, and dispatches `search-chips-updated`
 * (alias, filters, leftover, sort, count, engine) whenever the chip set changes. The
 * page that shows the results listens for it:
 *
 *   #[On('search-chips-updated')]
 *   public function filter(array $filters, string $leftover, ?array $sort): void
 *
 * and builds its query with SearchChips::for('posts')->applyChips(...).
 */
class Search extends Component
{
    #[Locked]
    public string $alias;

    #[Url(as: 'q', except: '')]
    public string $query = '';

    public array $chips = [];

    public string $leftover = '';

    public ?array $sort = null;

    public ?int $count = null;

    /** The engine behind the current chips: rules, system-one, ai, user. */
    public ?string $engine = null;

    /** True after the user removed or accepted a chip; an AI refine must not undo that. */
    public bool $edited = false;

    #[Locked]
    public string $placeholder = '';

    #[Locked]
    public int $debounce = 300;

    /** Show the filter controls under the search box. */
    #[Locked]
    public bool $panel = false;

    public function mount(string $for, ?string $placeholder = null, int $debounce = 300, bool $panel = false): void
    {
        $this->alias = $for;
        $this->placeholder = $placeholder ?? __('Search…');
        $this->debounce = $debounce;
        $this->panel = $panel;

        $this->manager()->authorize($this->alias);

        if ($this->query !== '') {
            $this->updatedQuery();
        }
    }

    /** Phase 1: the instant rules reading. Asks the browser to start phase 2 when an AI engine exists. */
    public function updatedQuery(): void
    {
        $this->manager()->authorize($this->alias);
        $this->edited = false;

        if (trim($this->query) === '') {
            $this->clear();

            return;
        }

        $pending = $this->manager()->search($this->query, $this->alias, refine: false);

        $this->show($pending);

        if ($pending->result->refinable) {
            $this->js('$wire.refine('.json_encode($this->query).')');
        }
    }

    /** Phase 2: the AI reading. Ignored when the query moved on or the user edited the chips. */
    public function refine(string $query): void
    {
        $this->manager()->authorize($this->alias);

        if ($query !== $this->query || $this->edited) {
            return;
        }

        $this->show($this->manager()->search($this->query, $this->alias));
    }

    public function remove(string $id): void
    {
        $this->reapply(array_filter($this->chips, fn ($chip) => ($chip['id'] ?? '') !== $id));
    }

    /** Turn a suggested chip into an applied one. */
    public function accept(string $id): void
    {
        $this->reapply(array_map(
            fn ($chip) => ($chip['id'] ?? '') === $id ? [...$chip, 'suggested' => false] : $chip,
            $this->chips,
        ));
    }

    /** A filter control changed: replace that slot of the field. */
    public function setFilter(string $field, string $operator, mixed $value = null): void
    {
        $this->reapply(ChipSet::set($this->chips, $field, $operator, $value));
    }

    public function unsetFilter(string $field): void
    {
        $this->reapply(ChipSet::unset($this->chips, $field));
    }

    public function clearSort(): void
    {
        $this->sort = null;
        $this->reapply($this->chips);
    }

    /** Drop the keyword search and keep the chips. */
    public function clearKeywords(): void
    {
        $this->leftover = '';
        $this->reapply($this->chips);
    }

    public function clear(): void
    {
        $this->manager()->authorize($this->alias);

        $this->query = '';
        $this->edited = false;

        $this->show($this->manager()->applyChips($this->alias, []));
    }

    public function render(): View
    {
        return view('search-chips::livewire.search', [
            'fields' => $this->panel ? $this->manager()->schema($this->alias)->toPanel() : [],
        ]);
    }

    private function reapply(array $chips): void
    {
        $this->manager()->authorize($this->alias);
        $this->edited = true;

        $this->show($this->manager()->applyChips($this->alias, array_values($chips), $this->leftover, $this->sort));
    }

    private function show(PendingSearch $pending): void
    {
        $this->chips = $pending->chips();
        $this->leftover = $pending->leftover();
        $this->sort = $pending->sort();
        $this->count = $pending->count();
        $this->engine = $pending->engine();

        $this->dispatch(
            'search-chips-updated',
            alias: $this->alias,
            filters: $this->chips,
            leftover: $this->leftover,
            sort: $this->sort,
            count: $this->count,
            engine: $this->engine,
        );
    }

    private function manager(): SearchChipsManager
    {
        return app(SearchChipsManager::class);
    }
}
