/**
 * Alpine adapter for the Blade component. Built to dist/search-chips.js and
 * served by the package, so a Blade app needs no build step.
 */
import { createSearchChips, type SearchChipsOptions, type SearchChipsState } from './core';

type AlpineComponent = SearchChipsState & Record<string, unknown>;

function searchChips(options: SearchChipsOptions) {
    return {
        ...({ query: '', chips: [], leftover: '', sort: null, count: null, results: [], engine: null, warnings: [], status: 'idle' } as SearchChipsState),
        ...options.initial,

        init(this: AlpineComponent & { $el: HTMLElement; $watch: (key: string, fn: (value: string) => void) => void }) {
            const core = createSearchChips({
                ...options,
                onChange: (state) => Object.assign(this, state),
                onFilters: (filters) => this.$el.dispatchEvent(new CustomEvent('search-chips:updated', { bubbles: true, detail: filters })),
            });

            this.core = core;
            this.$watch('query', (value) => value !== core.getState().query && core.setQuery(value));
        },

        remove(this: AlpineComponent, id: string) {
            return (this.core as ReturnType<typeof createSearchChips>).remove(id);
        },

        accept(this: AlpineComponent, id: string) {
            return (this.core as ReturnType<typeof createSearchChips>).accept(id);
        },

        clearSort(this: AlpineComponent) {
            return (this.core as ReturnType<typeof createSearchChips>).clearSort();
        },

        clearKeywords(this: AlpineComponent) {
            return (this.core as ReturnType<typeof createSearchChips>).clearKeywords();
        },

        setFilter(this: AlpineComponent, field: string, operator: string, value?: unknown) {
            return (this.core as ReturnType<typeof createSearchChips>).setFilter(field, operator, value);
        },

        unsetFilter(this: AlpineComponent, field: string) {
            return (this.core as ReturnType<typeof createSearchChips>).unsetFilter(field);
        },

        clear(this: AlpineComponent) {
            return (this.core as ReturnType<typeof createSearchChips>).clear();
        },

        get busy(): boolean {
            return (this as unknown as SearchChipsState).status !== 'idle' && (this as unknown as SearchChipsState).status !== 'error';
        },
    };
}

declare global {
    interface Window {
        searchChips: typeof searchChips;
        SearchChips: { createSearchChips: typeof createSearchChips };
    }
}

window.searchChips = searchChips;
window.SearchChips = { createSearchChips };
