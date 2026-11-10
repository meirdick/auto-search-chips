<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { LoaderCircle, Search, X } from 'lucide-vue-next';
import { computed } from 'vue';
import { toParams, type SearchChipsFilters, type SearchChipsOptions, type SearchChipsState } from './core';
import { useSearchChips } from './use-search-chips';

/**
 * A search box that turns what the user types into filters. The box holds only
 * the typed text; the row under it shows how the text was understood.
 *
 *   <SearchChips for="posts" :initial="searchChips" :reload="['posts', 'searchChips']" />
 *
 * This file is yours: restyle it or rebuild it on top of useSearchChips().
 */
const props = withDefaults(
    defineProps<{
        /** The registered model alias, for example "posts". */
        for: string;
        placeholder?: string;
        /** The `searchChips` prop from the controller: restores the chips after a page load. */
        initial?: Partial<SearchChipsState>;
        /** Inertia props to reload when the filters change, for example ['posts']. */
        reload?: string[];
        options?: Partial<SearchChipsOptions>;
    }>(),
    { placeholder: 'Search…' },
);

const emit = defineEmits<{ filters: [filters: SearchChipsFilters] }>();

const { state, setQuery, remove, accept, clearSort, clearKeywords, clear } = useSearchChips({
    ...props.options,
    for: props.for,
    initial: props.initial,
    onFilters: (filters) => {
        emit('filters', filters);

        if (props.reload) {
            router.reload({
                only: props.reload,
                data: toParams(filters),
                replace: true,
            });
        }
    },
});

const busy = computed(() => state.value.status !== 'idle' && state.value.status !== 'error');
const active = computed(() => state.value.chips.length > 0 || state.value.leftover !== '' || state.value.sort !== null);

const pill = 'inline-flex items-center rounded-full border text-sm font-medium';
const remover = 'rounded-r-full py-1 pr-2 pl-0.5 opacity-60 hover:opacity-100';
</script>

<template>
    <div class="w-full">
        <!-- The box holds only what the user typed. -->
        <div
            class="border-input bg-background focus-within:border-ring focus-within:ring-ring/50 flex w-full items-center gap-2 rounded-md border px-3 py-2 shadow-xs focus-within:ring-[3px]"
        >
            <Search class="text-muted-foreground size-4 shrink-0" aria-hidden="true" />
            <input
                type="search"
                :value="state.query"
                :placeholder="placeholder"
                :aria-label="placeholder"
                style="outline: none; box-shadow: none"
                class="placeholder:text-muted-foreground min-w-0 flex-1 border-0 bg-transparent p-0 text-sm [&::-webkit-search-cancel-button]:hidden"
                @input="setQuery(($event.target as HTMLInputElement).value)"
                @keydown.escape="clear()"
            />
            <LoaderCircle v-if="busy" class="text-muted-foreground size-4 shrink-0 animate-spin" aria-hidden="true" />
            <button
                v-if="active || state.query !== ''"
                type="button"
                class="text-muted-foreground hover:text-foreground shrink-0 text-xs font-medium hover:underline"
                @click="clear()"
            >
                Clear
            </button>
        </div>

        <!-- Under the box: how the search was understood. -->
        <div class="mt-2 flex min-h-7 flex-wrap items-center gap-1.5 px-1" role="status" aria-live="polite">
            <span v-if="active" class="text-muted-foreground text-xs">Showing</span>

            <ul v-if="state.chips.length" class="contents" aria-label="Active filters">
                <li
                    v-for="chip in state.chips"
                    :key="chip.id"
                    :class="[
                        pill,
                        chip.suggested
                            ? 'border-dashed border-amber-400 bg-amber-50 text-amber-900 dark:border-amber-500/50 dark:bg-amber-500/10 dark:text-amber-200'
                            : 'bg-secondary text-secondary-foreground',
                    ]"
                >
                    <button
                        v-if="chip.suggested"
                        type="button"
                        class="rounded-l-full py-0.5 pr-1 pl-2.5 hover:underline"
                        :aria-label="`Apply suggested filter: ${chip.label}`"
                        @click="accept(chip.id)"
                    >
                        {{ chip.label }}?
                    </button>
                    <span v-else class="py-0.5 pr-1 pl-2.5">{{ chip.label || chip.field }}</span>
                    <button type="button" :class="remover" :aria-label="`Remove filter: ${chip.label}`" @click="remove(chip.id)">
                        <X class="size-3.5" aria-hidden="true" />
                    </button>
                </li>
            </ul>

            <span v-if="state.leftover !== ''" :class="[pill, 'bg-background text-foreground/80']">
                <span class="py-0.5 pr-1 pl-2.5">Text: “{{ state.leftover }}”</span>
                <button type="button" :class="remover" aria-label="Remove text search" @click="clearKeywords()">
                    <X class="size-3.5" aria-hidden="true" />
                </button>
            </span>

            <span v-if="state.sort" :class="[pill, 'bg-background text-foreground/80']">
                <span class="py-0.5 pr-1 pl-2.5">Sort: {{ state.sort.label ?? state.sort.field }}</span>
                <button type="button" :class="remover" aria-label="Remove sort" @click="clearSort()">
                    <X class="size-3.5" aria-hidden="true" />
                </button>
            </span>

            <span v-if="state.status === 'error'" class="text-destructive text-xs">{{ state.warnings.join(' ') }}</span>

            <span v-if="state.count !== null" class="text-muted-foreground ml-auto text-xs">
                {{ state.count.toLocaleString() }} {{ state.count === 1 ? 'result' : 'results' }}
            </span>
        </div>
    </div>
</template>
