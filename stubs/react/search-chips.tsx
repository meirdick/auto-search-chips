import { router } from '@inertiajs/react';
import { LoaderCircle, Search, X } from 'lucide-react';
import type { ComponentProps, ReactNode } from 'react';
import { controlsFor, toParams, type PanelField, type SearchChipsFilters, type SearchChipsOptions, type SearchChipsState } from './core';
import { useSearchChips } from './use-search-chips';

type Props = Omit<ComponentProps<'div'>, 'onChange'> & {
    /** The registered model alias, for example "posts". */
    for: string;
    placeholder?: string;
    /** The `searchChips` prop from the controller: restores the chips after a page load. */
    initial?: Partial<SearchChipsState> & { fields?: PanelField[] };
    /** Inertia props to reload when the filters change, for example ['posts']. */
    reload?: string[];
    /** Called when the filters change. Use it in place of `reload` for a custom request. */
    onFilters?: (filters: SearchChipsFilters) => void;
    /** Show the filter controls under the search box. They need `initial.fields`. */
    panel?: boolean;
    options?: Partial<SearchChipsOptions>;
};

const pill = 'inline-flex items-center rounded-full border text-sm font-medium';
const control =
    'border-input bg-background placeholder:text-muted-foreground h-8 w-full min-w-0 rounded-md border px-2 text-sm outline-none focus:border-ring';

/**
 * A search box that turns what the user types into filters.
 *
 * The box holds only the typed text. The row under it shows how the text was
 * understood, as removable chips. With `panel`, the same filters also show as
 * controls: typing moves the controls, and a control changes the chips.
 *
 *   <SearchChips for="posts" initial={searchChips} reload={['posts', 'searchChips']} panel />
 *
 * This file is yours: restyle it or rebuild it on top of useSearchChips().
 */
export function SearchChips({ for: alias, placeholder = 'Search…', initial, reload, onFilters, panel = false, options, className = '', ...props }: Props) {
    const search = useSearchChips({
        ...options,
        for: alias,
        initial,
        onFilters: (filters) => {
            onFilters?.(filters);

            if (reload) {
                router.reload({ only: reload, data: toParams(filters), replace: true });
            }
        },
    });

    const busy = search.status !== 'idle' && search.status !== 'error';
    const active = search.chips.length > 0 || search.leftover !== '' || search.sort !== null;

    return (
        <div className={`w-full ${className}`} {...props}>
            <div className="border-input bg-background focus-within:border-ring focus-within:ring-ring/50 flex w-full items-center gap-2 rounded-md border px-3 py-2 shadow-xs focus-within:ring-[3px]">
                <Search className="text-muted-foreground size-4 shrink-0" aria-hidden />
                <input
                    type="search"
                    value={search.query}
                    onChange={(event) => search.setQuery(event.target.value)}
                    onKeyDown={(event) => event.key === 'Escape' && search.clear()}
                    placeholder={placeholder}
                    aria-label={placeholder}
                    style={{ outline: 'none', boxShadow: 'none' }}
                    className="placeholder:text-muted-foreground min-w-0 flex-1 border-0 bg-transparent p-0 text-sm shadow-none outline-none focus:ring-0 focus:outline-none focus-visible:ring-0 focus-visible:outline-none [&::-webkit-search-cancel-button]:hidden"
                />
                {busy && <LoaderCircle className="text-muted-foreground size-4 shrink-0 animate-spin" aria-hidden />}
                {(active || search.query !== '') && (
                    <button type="button" onClick={search.clear} className="text-muted-foreground hover:text-foreground shrink-0 text-xs font-medium hover:underline">
                        Clear
                    </button>
                )}
            </div>

            <div className="mt-2 flex min-h-7 flex-wrap items-center gap-1.5 px-1" role="status" aria-live="polite">
                {active && <span className="text-muted-foreground text-xs">Showing</span>}

                {search.chips.length > 0 && (
                    <ul className="contents" aria-label="Active filters">
                        {search.chips.map((chip) => (
                            <li
                                key={chip.id}
                                className={
                                    chip.suggested
                                        ? `${pill} border-dashed border-amber-400 bg-amber-50 text-amber-900 dark:border-amber-500/50 dark:bg-amber-500/10 dark:text-amber-200`
                                        : `${pill} bg-secondary text-secondary-foreground`
                                }
                            >
                                {chip.suggested ? (
                                    <button type="button" onClick={() => search.accept(chip.id)} className="rounded-l-full py-0.5 pr-1 pl-2.5 hover:underline" aria-label={`Apply suggested filter: ${chip.label}`}>
                                        {chip.label}?
                                    </button>
                                ) : (
                                    <span className="py-0.5 pr-1 pl-2.5">{chip.label || chip.field}</span>
                                )}
                                <Remove label={`Remove filter: ${chip.label}`} onClick={() => search.remove(chip.id)} />
                            </li>
                        ))}
                    </ul>
                )}

                {search.leftover !== '' && (
                    <span className={`${pill} bg-background text-foreground/80`}>
                        <span className="py-0.5 pr-1 pl-2.5">Text: “{search.leftover}”</span>
                        <Remove label="Remove text search" onClick={search.clearKeywords} />
                    </span>
                )}

                {search.sort && (
                    <span className={`${pill} bg-background text-foreground/80`}>
                        <span className="py-0.5 pr-1 pl-2.5">Sort: {search.sort.label ?? search.sort.field}</span>
                        <Remove label="Remove sort" onClick={search.clearSort} />
                    </span>
                )}

                {search.status === 'error' && <span className="text-destructive text-xs">{search.warnings.join(' ')}</span>}

                {search.count !== null && (
                    <span className="text-muted-foreground ml-auto text-xs">
                        {search.count.toLocaleString()} {search.count === 1 ? 'result' : 'results'}
                    </span>
                )}
            </div>

            {panel && initial?.fields && initial.fields.length > 0 && (
                <fieldset className="bg-muted/40 mt-2 grid gap-x-4 gap-y-3 rounded-md border p-3 sm:grid-cols-2 lg:grid-cols-3">
                    <legend className="text-muted-foreground px-1 text-xs font-medium">Filters</legend>
                    {initial.fields.map((field) => (
                        <Control key={field.name} field={field} search={search} />
                    ))}
                </fieldset>
            )}
        </div>
    );
}

function Remove({ label, onClick }: { label: string; onClick: () => void }) {
    return (
        <button type="button" onClick={onClick} className="rounded-r-full py-1 pr-2 pl-0.5 opacity-60 hover:opacity-100" aria-label={label}>
            <X className="size-3.5" aria-hidden />
        </button>
    );
}

function Toggle({ on, onClick, children }: { on: boolean; onClick: () => void; children: ReactNode }) {
    return (
        <button
            type="button"
            aria-pressed={on}
            onClick={onClick}
            className={`rounded-md border px-2 py-1 text-xs font-medium ${on ? 'bg-primary text-primary-foreground border-primary' : 'bg-background hover:border-ring'}`}
        >
            {children}
        </button>
    );
}

/** One field of the panel. It reads its value from the chips, so typing in the search box moves it. */
function Control({ field, search }: { field: PanelField; search: ReturnType<typeof useSearchChips> }) {
    const state = controlsFor(search.chips, field.name);
    const text = (value: unknown) => (value === null || value === undefined ? '' : String(value));
    const day = (value: unknown) => text(value).slice(0, 10);
    // Uncontrolled inputs keyed by their value: a parse can change them, and the user can type freely.
    const input = (type: string, operator: string, value: string, label: string, placeholder?: string) => (
        <input
            key={`${operator}:${value}`}
            type={type}
            step={type === 'number' ? 'any' : undefined}
            defaultValue={value}
            placeholder={placeholder}
            aria-label={`${field.label} ${label}`}
            className={control}
            onBlur={(event) => event.target.value !== value && search.setFilter(field.name, operator, event.target.value)}
            onKeyDown={(event) => event.key === 'Enter' && event.currentTarget.blur()}
        />
    );

    return (
        <div className="min-w-0">
            <div className="text-muted-foreground mb-1 text-xs font-medium">{field.label}</div>

            {field.kind === 'enum' && (
                <div className="flex flex-wrap gap-1">
                    {field.options.map((option) => {
                        const on = text(state.exact) === String(option.value);

                        return (
                            <Toggle key={option.value} on={on} onClick={() => (on ? search.unsetFilter(field.name) : search.setFilter(field.name, 'equals', option.value))}>
                                {option.label}
                            </Toggle>
                        );
                    })}
                </div>
            )}

            {field.kind === 'boolean' && (
                <div className="flex gap-1">
                    <Toggle on={state.bool === true} onClick={() => (state.bool === true ? search.unsetFilter(field.name) : search.setFilter(field.name, 'is_true'))}>
                        Yes
                    </Toggle>
                    <Toggle on={state.bool === false} onClick={() => (state.bool === false ? search.unsetFilter(field.name) : search.setFilter(field.name, 'is_false'))}>
                        No
                    </Toggle>
                </div>
            )}

            {field.kind === 'number' && (
                <div className="flex items-center gap-1">
                    {input('number', 'gte', text(state.min ?? state.exact), 'minimum', 'Min')}
                    <span className="text-muted-foreground">–</span>
                    {input('number', 'lte', text(state.max ?? state.exact), 'maximum', 'Max')}
                </div>
            )}

            {(field.kind === 'date' || field.kind === 'datetime') && (
                <div className="flex items-center gap-1">
                    {input('date', 'after', day(state.min ?? state.exact), 'from')}
                    <span className="text-muted-foreground">–</span>
                    {input('date', 'before', day(state.max ?? state.exact), 'to')}
                </div>
            )}

            {field.kind === 'text' && input('text', 'contains', text(state.exact), 'contains', 'contains…')}
        </div>
    );
}
