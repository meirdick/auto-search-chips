import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
import { createSearchChips, type SearchChips, type SearchChipsOptions, type SearchChipsState } from './core';

/**
 * React binding of the headless core. Returns the live state and the actions.
 *
 *   const search = useSearchChips({ for: 'posts', onFilters: (filters) => ... });
 */
export function useSearchChips(options: SearchChipsOptions): SearchChipsState & Omit<SearchChips, 'getState' | 'subscribe' | 'destroy'> {
    // The callbacks may change on every render; the core is created once.
    const latest = useRef(options);
    latest.current = options;

    const [core] = useState(() =>
        createSearchChips({
            ...options,
            onChange: (state) => latest.current.onChange?.(state),
            onFilters: (filters) => latest.current.onFilters?.(filters),
        }),
    );

    useEffect(() => () => core.destroy(), [core]);

    const state = useSyncExternalStore(core.subscribe, core.getState, core.getState);

    const { setQuery, remove, accept, setFilter, unsetFilter, clearSort, clearKeywords, clear } = core;

    return { ...state, setQuery, remove, accept, setFilter, unsetFilter, clearSort, clearKeywords, clear };
}
