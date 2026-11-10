import { onBeforeUnmount, shallowRef, type ShallowRef } from 'vue';
import { createSearchChips, type SearchChips, type SearchChipsOptions, type SearchChipsState } from './core';

/**
 * Vue binding of the headless core.
 *
 *   const { state, setQuery, remove, accept, clear } = useSearchChips({ for: 'posts' });
 */
export function useSearchChips(options: SearchChipsOptions): { state: ShallowRef<SearchChipsState> } & Omit<SearchChips, 'getState' | 'subscribe' | 'destroy'> {
    const core = createSearchChips(options);
    const state = shallowRef(core.getState());
    const stop = core.subscribe((next) => (state.value = next));

    onBeforeUnmount(() => {
        stop();
        core.destroy();
    });

    const { setQuery, remove, accept, setFilter, unsetFilter, clearSort, clearKeywords, clear } = core;

    return { state, setQuery, remove, accept, setFilter, unsetFilter, clearSort, clearKeywords, clear };
}
