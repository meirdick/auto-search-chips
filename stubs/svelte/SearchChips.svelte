<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { LoaderCircle, Search, X } from 'lucide-svelte';
    import { onDestroy } from 'svelte';
    import { createSearchChips, toParams, type SearchChipsFilters, type SearchChipsOptions, type SearchChipsState } from './core';

    /**
     * A search box that turns what the user types into removable filter chips.
     *
     *   <SearchChips for="posts" initial={searchChips} reload={['posts', 'searchChips']} />
     *
     * This file is yours: restyle it or rebuild it on top of createSearchChips().
     */
    type Props = {
        /** The registered model alias, for example "posts". */
        for: string;
        placeholder?: string;
        /** The `searchChips` prop from the controller: restores the chips after a page load. */
        initial?: Partial<SearchChipsState>;
        /** Inertia props to reload when the filters change, for example ['posts']. */
        reload?: string[];
        onfilters?: (filters: SearchChipsFilters) => void;
        options?: Partial<SearchChipsOptions>;
    };

    let { for: alias, placeholder = 'Search…', initial, reload, onfilters, options }: Props = $props();

    const core = createSearchChips({
        ...options,
        for: alias,
        initial,
        onFilters: (filters) => {
            onfilters?.(filters);

            if (reload) {
                router.reload({
                    only: reload,
                    data: toParams(filters),
                    replace: true,
                });
            }
        },
    });

    let search = $state.raw(core.getState());
    const stop = core.subscribe((next) => (search = next));
    const busy = $derived(search.status !== 'idle' && search.status !== 'error');
    const active = $derived(search.chips.length > 0 || search.leftover !== '' || search.sort !== null);

    const pill = 'inline-flex items-center rounded-full border text-sm font-medium';
    const remover = 'rounded-r-full py-1 pr-2 pl-0.5 opacity-60 hover:opacity-100';

    onDestroy(() => {
        stop();
        core.destroy();
    });
</script>

<div class="w-full">
    <!-- The box holds only what the user typed. -->
    <div
        class="border-input bg-background focus-within:border-ring focus-within:ring-ring/50 flex w-full items-center gap-2 rounded-md border px-3 py-2 shadow-xs focus-within:ring-[3px]"
    >
        <Search class="text-muted-foreground size-4 shrink-0" aria-hidden="true" />
        <input
            type="search"
            value={search.query}
            {placeholder}
            aria-label={placeholder}
            style="outline: none; box-shadow: none"
            class="placeholder:text-muted-foreground min-w-0 flex-1 border-0 bg-transparent p-0 text-sm [&::-webkit-search-cancel-button]:hidden"
            oninput={(event) => core.setQuery(event.currentTarget.value)}
            onkeydown={(event) => event.key === 'Escape' && core.clear()}
        />
        {#if busy}
            <LoaderCircle class="text-muted-foreground size-4 shrink-0 animate-spin" aria-hidden="true" />
        {/if}
        {#if active || search.query !== ''}
            <button type="button" class="text-muted-foreground hover:text-foreground shrink-0 text-xs font-medium hover:underline" onclick={() => core.clear()}>
                Clear
            </button>
        {/if}
    </div>

    <!-- Under the box: how the search was understood. -->
    <div class="mt-2 flex min-h-7 flex-wrap items-center gap-1.5 px-1" role="status" aria-live="polite">
        {#if active}<span class="text-muted-foreground text-xs">Showing</span>{/if}

        {#if search.chips.length}
            <ul class="contents" aria-label="Active filters">
                {#each search.chips as chip (chip.id)}
                    <li
                        class="{pill} {chip.suggested
                            ? 'border-dashed border-amber-400 bg-amber-50 text-amber-900 dark:border-amber-500/50 dark:bg-amber-500/10 dark:text-amber-200'
                            : 'bg-secondary text-secondary-foreground'}"
                    >
                        {#if chip.suggested}
                            <button
                                type="button"
                                class="rounded-l-full py-0.5 pr-1 pl-2.5 hover:underline"
                                aria-label={`Apply suggested filter: ${chip.label}`}
                                onclick={() => core.accept(chip.id)}
                            >
                                {chip.label}?
                            </button>
                        {:else}
                            <span class="py-0.5 pr-1 pl-2.5">{chip.label || chip.field}</span>
                        {/if}
                        <button type="button" class={remover} aria-label={`Remove filter: ${chip.label}`} onclick={() => core.remove(chip.id)}>
                            <X class="size-3.5" aria-hidden="true" />
                        </button>
                    </li>
                {/each}
            </ul>
        {/if}

        {#if search.leftover !== ''}
            <span class="{pill} bg-background text-foreground/80">
                <span class="py-0.5 pr-1 pl-2.5">Text: “{search.leftover}”</span>
                <button type="button" class={remover} aria-label="Remove text search" onclick={() => core.clearKeywords()}>
                    <X class="size-3.5" aria-hidden="true" />
                </button>
            </span>
        {/if}

        {#if search.sort}
            <span class="{pill} bg-background text-foreground/80">
                <span class="py-0.5 pr-1 pl-2.5">Sort: {search.sort.label ?? search.sort.field}</span>
                <button type="button" class={remover} aria-label="Remove sort" onclick={() => core.clearSort()}>
                    <X class="size-3.5" aria-hidden="true" />
                </button>
            </span>
        {/if}

        {#if search.status === 'error'}<span class="text-destructive text-xs">{search.warnings.join(' ')}</span>{/if}

        {#if search.count !== null}
            <span class="text-muted-foreground ml-auto text-xs">{search.count.toLocaleString()} {search.count === 1 ? 'result' : 'results'}</span>
        {/if}
    </div>
</div>
