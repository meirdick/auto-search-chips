@props([
    'for',                       // registered alias, e.g. "posts"
    'placeholder' => __('Search…'),
    'debounce' => 250,
    'limit' => 25,
    'withResults' => false,      // also fetch result rows into the `results` Alpine property
])

@php
    \Meirdick\SearchChips\Facades\SearchChips::authorize($for);

    $options = [
        'for' => $for,
        'baseUrl' => url(config('search-chips.routes.prefix', 'search-chips')),
        'debounce' => (int) $debounce,
        'limit' => (int) $limit,
        'withResults' => (bool) $withResults,
    ];
@endphp

@once
    <script src="{{ route('search-chips.script') }}"></script>
@endonce

{{-- Listen for `search-chips:updated` on this element or any ancestor: detail = { query, chips, leftover, sort, count }. --}}
<div x-data="searchChips(@js($options))" data-search-chips="{{ $for }}" {{ $attributes->class('w-full') }}>
    {{-- The box holds only what the user typed. --}}
    <div class="flex w-full items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-2 shadow-xs focus-within:border-zinc-400 focus-within:ring-2 focus-within:ring-zinc-200 dark:border-zinc-700 dark:bg-zinc-900 dark:focus-within:border-zinc-500 dark:focus-within:ring-zinc-700">
        <svg class="size-4 shrink-0 text-zinc-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11ZM2 9a7 7 0 1 1 12.452 4.391l3.328 3.329a.75.75 0 1 1-1.06 1.06l-3.329-3.328A7 7 0 0 1 2 9Z" clip-rule="evenodd"/>
        </svg>

        <input type="search" x-model="query" @keydown.escape="clear()"
               placeholder="{{ $placeholder }}" aria-label="{{ $placeholder }}" style="outline: none; box-shadow: none"
               class="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm text-zinc-900 placeholder:text-zinc-400 focus:outline-none focus:ring-0 dark:text-zinc-100 [&::-webkit-search-cancel-button]:hidden">

        <span x-show="busy" x-cloak class="size-3 shrink-0 animate-spin rounded-full border-2 border-zinc-300 border-t-zinc-600" aria-hidden="true"></span>

        <button type="button" x-show="chips.length > 0 || query !== '' || sort" x-cloak @click="clear()"
                class="shrink-0 text-xs font-medium text-zinc-500 hover:text-zinc-900 hover:underline dark:hover:text-zinc-100">
            {{ __('Clear') }}
        </button>
    </div>

    {{-- Under the box: how the search was understood. --}}
    <div class="mt-2 flex min-h-7 flex-wrap items-center gap-1.5 px-1 text-sm" role="status" aria-live="polite">
        <span x-show="chips.length > 0 || leftover !== '' || sort" x-cloak class="text-xs text-zinc-500">{{ __('Showing') }}</span>

        <ul class="contents" aria-label="{{ __('Active filters') }}">
            <template x-for="chip in chips" :key="chip.id">
                <li class="inline-flex items-center rounded-full border font-medium"
                    :class="chip.suggested
                        ? 'border-dashed border-amber-400 bg-amber-50 text-amber-900 dark:border-amber-500/50 dark:bg-amber-500/10 dark:text-amber-200'
                        : 'border-zinc-200 bg-zinc-100 text-zinc-800 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100'">
                    <button type="button" x-show="chip.suggested" @click="accept(chip.id)" class="rounded-l-full py-0.5 pl-2.5 pr-1 hover:underline"
                            :aria-label="@js(__('Apply suggested filter:')) + ' ' + chip.label" x-text="chip.label + '?'"></button>
                    <span x-show="!chip.suggested" class="py-0.5 pl-2.5 pr-1" x-text="chip.label || chip.field"></span>
                    <button type="button" @click="remove(chip.id)" class="rounded-r-full py-1 pl-0.5 pr-2 opacity-60 hover:opacity-100"
                            :aria-label="@js(__('Remove filter:')) + ' ' + chip.label"><svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/></svg></button>
                </li>
            </template>
        </ul>

        <span x-show="leftover !== ''" x-cloak class="inline-flex items-center rounded-full border border-zinc-200 bg-white font-medium text-zinc-700 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">
            <span class="py-0.5 pl-2.5 pr-1" x-text="@js(__('Text:')) + ' “' + leftover + '”'"></span>
            <button type="button" @click="clearKeywords()" class="rounded-r-full py-1 pl-0.5 pr-2 opacity-60 hover:opacity-100" aria-label="{{ __('Remove text search') }}"><svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/></svg></button>
        </span>

        <span x-show="sort" x-cloak class="inline-flex items-center rounded-full border border-zinc-200 bg-white font-medium text-zinc-700 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">
            <span class="py-0.5 pl-2.5 pr-1" x-text="@js(__('Sort:')) + ' ' + (sort ? (sort.label || sort.field) : '')"></span>
            <button type="button" @click="clearSort()" class="rounded-r-full py-1 pl-0.5 pr-2 opacity-60 hover:opacity-100" aria-label="{{ __('Remove sort') }}"><svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/></svg></button>
        </span>

        <template x-for="warning in (status === 'error' ? warnings : [])"><span class="text-xs text-red-600" x-text="warning"></span></template>

        <span x-show="count !== null" x-cloak class="ml-auto text-xs text-zinc-500" x-text="count + ' ' + (count === 1 ? @js(__('result')) : @js(__('results')))"></span>
    </div>

    {{ $slot }}
</div>
