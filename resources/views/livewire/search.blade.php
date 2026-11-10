@php
    use Meirdick\SearchChips\Data\ChipSet;

    $control = 'h-8 w-full min-w-0 rounded-md border border-zinc-200 bg-white px-2 text-sm text-zinc-900 placeholder:text-zinc-400 focus:border-zinc-400 focus:outline-none dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100';
    $toggle = fn (bool $on) => 'rounded-md border px-2 py-1 text-xs font-medium '.($on
        ? 'border-zinc-900 bg-zinc-900 text-white dark:border-zinc-100 dark:bg-zinc-100 dark:text-zinc-900'
        : 'border-zinc-200 bg-white text-zinc-700 hover:border-zinc-400 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300');
    $day = fn ($value) => is_string($value) ? substr($value, 0, 10) : '';
@endphp

<div data-search-chips="{{ $alias }}" class="w-full">
    {{-- The box holds only what the user typed. --}}
    <div class="flex w-full items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-2 shadow-xs focus-within:border-zinc-400 focus-within:ring-2 focus-within:ring-zinc-200 dark:border-zinc-700 dark:bg-zinc-900 dark:focus-within:border-zinc-500 dark:focus-within:ring-zinc-700">
        <svg class="size-4 shrink-0 text-zinc-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11ZM2 9a7 7 0 1 1 12.452 4.391l3.328 3.329a.75.75 0 1 1-1.06 1.06l-3.329-3.328A7 7 0 0 1 2 9Z" clip-rule="evenodd"/>
        </svg>

        <input type="search" wire:model.live.debounce.{{ $debounce }}ms="query" wire:keydown.escape="clear"
               placeholder="{{ $placeholder }}" aria-label="{{ $placeholder }}" style="outline: none; box-shadow: none"
               class="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm text-zinc-900 placeholder:text-zinc-400 focus:outline-none focus:ring-0 dark:text-zinc-100 [&::-webkit-search-cancel-button]:hidden">

        <span wire:loading.delay class="size-3 shrink-0 animate-spin rounded-full border-2 border-zinc-300 border-t-zinc-600" aria-hidden="true"></span>

        @if ($chips !== [] || $query !== '' || $sort !== null)
            <button type="button" wire:click="clear"
                    class="shrink-0 text-xs font-medium text-zinc-500 hover:text-zinc-900 hover:underline dark:hover:text-zinc-100">
                {{ __('Clear') }}
            </button>
        @endif
    </div>

    {{-- Under the box: how the search was understood. --}}
    <div class="mt-2 flex min-h-7 flex-wrap items-center gap-1.5 px-1 text-sm" role="status" aria-live="polite">
        @if ($chips !== [] || $leftover !== '' || $sort !== null)
            <span class="text-xs text-zinc-500">{{ __('Showing') }}</span>
        @endif

        @if ($chips !== [])
            <ul class="contents" aria-label="{{ __('Active filters') }}">
                @foreach ($chips as $chip)
                    <li wire:key="chip-{{ $chip['id'] }}"
                        @class([
                            'inline-flex items-center rounded-full border font-medium',
                            'border-dashed border-amber-400 bg-amber-50 text-amber-900 dark:border-amber-500/50 dark:bg-amber-500/10 dark:text-amber-200' => $chip['suggested'] ?? false,
                            'border-zinc-200 bg-zinc-100 text-zinc-800 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100' => ! ($chip['suggested'] ?? false),
                        ])>
                        @if ($chip['suggested'] ?? false)
                            <button type="button" wire:click="accept(@js($chip['id']))"
                                    class="rounded-l-full py-0.5 pl-2.5 pr-1 hover:underline"
                                    aria-label="{{ __('Apply suggested filter:') }} {{ $chip['label'] }}">{{ $chip['label'] }}?</button>
                        @else
                            <span class="py-0.5 pl-2.5 pr-1">{{ $chip['label'] }}</span>
                        @endif
                        <button type="button" wire:click="remove(@js($chip['id']))"
                                class="rounded-r-full py-1 pl-0.5 pr-2 opacity-60 hover:opacity-100"
                                aria-label="{{ __('Remove filter:') }} {{ $chip['label'] }}">
                            <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/></svg>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($leftover !== '')
            <span class="inline-flex items-center rounded-full border border-zinc-200 bg-white font-medium text-zinc-700 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">
                <span class="py-0.5 pl-2.5 pr-1">{{ __('Text:') }} “{{ $leftover }}”</span>
                <button type="button" wire:click="clearKeywords" class="rounded-r-full py-1 pl-0.5 pr-2 opacity-60 hover:opacity-100" aria-label="{{ __('Remove text search') }}">
                    <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/></svg>
                </button>
            </span>
        @endif

        @if ($sort !== null)
            <span class="inline-flex items-center rounded-full border border-zinc-200 bg-white font-medium text-zinc-700 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">
                <span class="py-0.5 pl-2.5 pr-1">{{ __('Sort:') }} {{ $sort['label'] ?? $sort['field'] }}</span>
                <button type="button" wire:click="clearSort" class="rounded-r-full py-1 pl-0.5 pr-2 opacity-60 hover:opacity-100" aria-label="{{ __('Remove sort') }}">
                    <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/></svg>
                </button>
            </span>
        @endif

        @if ($count !== null)
            <span class="ml-auto text-xs text-zinc-500">{{ trans_choice('{1} :count result|[0,*] :count results', $count, ['count' => number_format($count)]) }}</span>
        @endif
    </div>

    {{-- The same filters as controls. Typing moves them; moving them changes the chips. --}}
    @if ($panel && $fields !== [])
        <fieldset class="mt-2 grid gap-x-4 gap-y-3 rounded-lg border border-zinc-200 bg-zinc-50 p-3 sm:grid-cols-2 lg:grid-cols-3 dark:border-zinc-700 dark:bg-zinc-900/50">
            <legend class="px-1 text-xs font-medium text-zinc-500">{{ __('Filters') }}</legend>

            @foreach ($fields as $field)
                @php($state = ChipSet::controls($chips, $field['name']))

                <div class="min-w-0" wire:key="control-{{ $field['name'] }}-{{ md5(json_encode($state)) }}">
                    <div class="mb-1 text-xs font-medium text-zinc-600 dark:text-zinc-400">{{ $field['label'] }}</div>

                    @switch($field['kind'])
                        @case('enum')
                            <div class="flex flex-wrap gap-1">
                                @foreach ($field['options'] as $option)
                                    @php($on = (string) $state['exact'] === (string) $option['value'])
                                    <button type="button" aria-pressed="{{ $on ? 'true' : 'false' }}" class="{{ $toggle($on) }}"
                                            wire:click="{{ $on ? 'unsetFilter('.\Illuminate\Support\Js::from($field['name']).')' : 'setFilter('.\Illuminate\Support\Js::from($field['name']).", 'equals', ".\Illuminate\Support\Js::from($option['value']).')' }}">{{ $option['label'] }}</button>
                                @endforeach
                            </div>
                            @break

                        @case('boolean')
                            <div class="flex gap-1">
                                <button type="button" aria-pressed="{{ $state['bool'] === true ? 'true' : 'false' }}" class="{{ $toggle($state['bool'] === true) }}"
                                        wire:click="{{ $state['bool'] === true ? 'unsetFilter' : 'setFilter' }}(@js($field['name']){{ $state['bool'] === true ? '' : ", 'is_true'" }})">{{ __('Yes') }}</button>
                                <button type="button" aria-pressed="{{ $state['bool'] === false ? 'true' : 'false' }}" class="{{ $toggle($state['bool'] === false) }}"
                                        wire:click="{{ $state['bool'] === false ? 'unsetFilter' : 'setFilter' }}(@js($field['name']){{ $state['bool'] === false ? '' : ", 'is_false'" }})">{{ __('No') }}</button>
                            </div>
                            @break

                        @case('number')
                            <div class="flex items-center gap-1">
                                <input type="number" step="any" class="{{ $control }}" placeholder="{{ __('Min') }}" aria-label="{{ $field['label'] }} {{ __('minimum') }}"
                                       value="{{ $state['min'] ?? $state['exact'] }}" wire:change="setFilter(@js($field['name']), 'gte', $event.target.value)">
                                <span class="text-zinc-400">–</span>
                                <input type="number" step="any" class="{{ $control }}" placeholder="{{ __('Max') }}" aria-label="{{ $field['label'] }} {{ __('maximum') }}"
                                       value="{{ $state['max'] ?? $state['exact'] }}" wire:change="setFilter(@js($field['name']), 'lte', $event.target.value)">
                            </div>
                            @break

                        @case('date')
                        @case('datetime')
                            <div class="flex items-center gap-1">
                                <input type="date" class="{{ $control }}" aria-label="{{ $field['label'] }} {{ __('from') }}"
                                       value="{{ $day($state['min'] ?? $state['exact']) }}" wire:change="setFilter(@js($field['name']), 'after', $event.target.value)">
                                <span class="text-zinc-400">–</span>
                                <input type="date" class="{{ $control }}" aria-label="{{ $field['label'] }} {{ __('to') }}"
                                       value="{{ $day($state['max'] ?? $state['exact']) }}" wire:change="setFilter(@js($field['name']), 'before', $event.target.value)">
                            </div>
                            @break

                        @default
                            <input type="text" class="{{ $control }}" placeholder="{{ __('contains…') }}" aria-label="{{ $field['label'] }}"
                                   value="{{ is_scalar($state['exact']) ? $state['exact'] : '' }}" wire:change="setFilter(@js($field['name']), 'contains', $event.target.value)">
                    @endswitch
                </div>
            @endforeach
        </fieldset>
    @endif
</div>
