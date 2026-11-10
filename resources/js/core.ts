/**
 * Search Chips headless core. No dependencies, no framework.
 *
 * It owns the state of one search box (query, chips, count) and talks to the
 * package's JSON routes. Every UI adapter (Alpine, React, Vue, Svelte) is a
 * thin view over this file.
 */

export type Chip = {
    id: string;
    field: string;
    operator: string;
    value: unknown;
    negated: boolean;
    label: string;
    source: string;
    confidence: number;
    /** A suggested chip is shown but not applied until the user accepts it. */
    suggested: boolean;
    unit?: string | null;
    spans?: { start: number; end: number }[] | null;
};

export type Sort = { field: string; direction: 'asc' | 'desc'; label?: string | null };

export type Status = 'idle' | 'parsing' | 'refining' | 'applying' | 'error';

export type SearchChipsState = {
    query: string;
    chips: Chip[];
    leftover: string;
    sort: Sort | null;
    count: number | null;
    results: unknown[];
    engine: string | null;
    warnings: string[];
    status: Status;
};

/** The settled filter set: what a results list needs in order to reload. */
export type SearchChipsFilters = Pick<SearchChipsState, 'query' | 'chips' | 'leftover' | 'sort' | 'count'>;

export type SearchChipsOptions = {
    /** The registered model alias, for example "posts". */
    for: string;
    /** Route prefix. Default "/search-chips". */
    baseUrl?: string;
    /** Milliseconds to wait after the last keystroke. Default 250. */
    debounce?: number;
    /** Ask the routes for result rows as well as chips. Default false. */
    withResults?: boolean;
    limit?: number;
    /** State to start from, for example the chips the server rendered the page with. */
    initial?: Partial<SearchChipsState>;
    /** Called on every state change. */
    onChange?: (state: SearchChipsState) => void;
    /** Called when the filter set settles: after a parse, a refine, a chip edit, or a clear. */
    onFilters?: (filters: SearchChipsFilters) => void;
    fetch?: typeof fetch;
    headers?: () => Record<string, string>;
};

/** One field of the filter panel, from the controller's `searchChips.fields`. */
export type PanelField = {
    name: string;
    label: string;
    kind: 'text' | 'number' | 'date' | 'datetime' | 'boolean' | 'enum';
    options: { value: string | number; label: string }[];
};

export type SearchChips = {
    getState(): SearchChipsState;
    subscribe(listener: (state: SearchChipsState) => void): () => void;
    setQuery(query: string): void;
    remove(id: string): Promise<void>;
    accept(id: string): Promise<void>;
    /** A filter control changed: replace that slot (lower bound, upper bound or exact value) of the field. */
    setFilter(field: string, operator: string, value?: unknown): Promise<void>;
    unsetFilter(field: string): Promise<void>;
    clearSort(): Promise<void>;
    /** Drop the keyword search and keep the chips. */
    clearKeywords(): Promise<void>;
    clear(): void;
    destroy(): void;
};

type Payload = Partial<SearchChipsState> & { filters?: Chip[]; refinable?: boolean };

const empty: SearchChipsState = {
    query: '',
    chips: [],
    leftover: '',
    sort: null,
    count: null,
    results: [],
    engine: null,
    warnings: [],
    status: 'idle',
};

export function createSearchChips(options: SearchChipsOptions): SearchChips {
    const base = `${(options.baseUrl ?? '/search-chips').replace(/\/$/, '')}/${encodeURIComponent(options.for)}`;
    const debounce = options.debounce ?? 250;
    const listeners = new Set<(state: SearchChipsState) => void>();

    let state: SearchChipsState = { ...empty, ...options.initial };
    let timer: ReturnType<typeof setTimeout> | undefined;
    let controller: AbortController | undefined;
    let edited = false; // the user changed the chips; a late AI refine must not undo that

    const set = (patch: Partial<SearchChipsState>) => {
        state = { ...state, ...patch };
        listeners.forEach((listener) => listener(state));
        options.onChange?.(state);
    };

    const settle = () => {
        const { query, chips, leftover, sort, count } = state;
        options.onFilters?.({ query, chips, leftover, sort, count });
    };

    const extras = () => (options.withResults ? { with: 'results', limit: options.limit ?? 25 } : {});

    const post = async (path: string, body: object, signal?: AbortSignal): Promise<Payload> => {
        const response = await (options.fetch ?? fetch)(`${base}/${path}`, {
            method: 'POST',
            credentials: 'same-origin',
            signal,
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...csrfHeaders(),
                ...options.headers?.(),
            },
            body: JSON.stringify(body),
        });

        if (!response.ok) {
            throw new Error(`Search chips request failed (${response.status})`);
        }

        return response.json();
    };

    const take = (payload: Payload, status: Status) =>
        set({
            chips: payload.filters ?? [],
            leftover: payload.leftover ?? '',
            sort: payload.sort ?? null,
            count: payload.count ?? null,
            results: payload.results ?? [],
            engine: payload.engine ?? null,
            warnings: payload.warnings ?? [],
            status,
        });

    const parse = async () => {
        controller?.abort();
        const current = (controller = new AbortController());
        const query = state.query;
        edited = false;

        try {
            // Phase 1: the rules reading. No AI call, so it returns at once.
            set({ status: 'parsing' });
            const first = await post('parse', { query, refine: false, ...extras() }, current.signal);
            take(first, first.refinable ? 'refining' : 'idle');
            settle();

            if (!first.refinable) {
                return;
            }

            // Phase 2: the AI reading replaces phase 1, unless the user moved on.
            const second = await post('parse', { query, refine: true, ...extras() }, current.signal);

            if (edited || state.query !== query) {
                return set({ status: 'idle' });
            }

            take(second, 'idle');
            settle();
        } catch (error) {
            if ((error as Error).name !== 'AbortError') {
                set({ status: 'error', warnings: ['Search failed. Try again.'] });
            }
        }
    };

    const apply = async (chips: Chip[]) => {
        controller?.abort();
        const current = (controller = new AbortController());
        edited = true;
        set({ chips, status: 'applying' });

        try {
            // A chip that a control just made has no label yet; the server writes it.
            const body = chips.map(({ label, ...chip }) => (label ? { ...chip, label } : chip));
            const payload = await post('apply', { chips: body, leftover: state.leftover, sort: state.sort, ...extras() }, current.signal);
            take(payload, 'idle');
            settle();
        } catch (error) {
            if ((error as Error).name !== 'AbortError') {
                set({ status: 'error', warnings: ['Could not update the results.'] });
            }
        }
    };

    const reset = (query: string) => {
        controller?.abort();
        edited = false;
        set({ ...empty, query });
        settle();
    };

    return {
        getState: () => state,

        subscribe(listener) {
            listeners.add(listener);

            return () => listeners.delete(listener);
        },

        setQuery(query) {
            clearTimeout(timer);
            set({ query });

            if (query.trim() === '') {
                return reset(query);
            }

            timer = setTimeout(parse, debounce);
        },

        remove: (id) => apply(state.chips.filter((chip) => chip.id !== id)),

        accept: (id) => apply(state.chips.map((chip) => (chip.id === id ? { ...chip, suggested: false } : chip))),

        setFilter: (field, operator, value) => apply(setChip(state.chips, field, operator, value)),

        unsetFilter: (field) => apply(state.chips.filter((chip) => chip.field !== field)),

        clearSort() {
            set({ sort: null });

            return apply(state.chips);
        },

        clearKeywords() {
            set({ leftover: '' });

            return apply(state.chips);
        },

        clear() {
            clearTimeout(timer);
            reset('');
        },

        destroy() {
            clearTimeout(timer);
            controller?.abort();
            listeners.clear();
        },
    };
}

const slots: Partial<Record<string, 'min' | 'max'>> = { gt: 'min', gte: 'min', after: 'min', lt: 'max', lte: 'max', before: 'max' };

/** The chip set after one control change. Mirrors Data\ChipSet::set() on the server. */
function setChip(chips: Chip[], field: string, operator: string, value: unknown): Chip[] {
    const slot = slots[operator] ?? 'exact';

    // A range chip is two bounds. Split it, so a change to one bound keeps the other.
    if (slot !== 'exact') {
        const date = operator === 'after' || operator === 'before';
        const id = `user:${field.replace(/[^A-Za-z0-9_.-]/g, '')}`;

        chips = chips.flatMap((chip) => {
            if (chip.field !== field || chip.operator !== 'between' || !Array.isArray(chip.value) || chip.value.length < 2 || chip.negated) {
                return [chip];
            }

            const bound = { ...chip, label: '', source: 'user', spans: undefined };

            return [
                { ...bound, id: `${id}:min`, operator: date ? 'after' : 'gte', value: chip.value[0] },
                { ...bound, id: `${id}:max`, operator: date ? 'before' : 'lte', value: chip.value[1] },
            ];
        });
    }

    const kept = chips.filter((chip) => {
        if (chip.field !== field) {
            return true;
        }

        const existing = slots[chip.operator] ?? 'exact';

        return existing !== slot && existing !== 'exact' && slot !== 'exact';
    });

    const empty = value === null || value === undefined || value === '';

    if (empty && operator !== 'is_true' && operator !== 'is_false') {
        return kept;
    }

    return [
        ...kept,
        { id: `user:${field.replace(/[^A-Za-z0-9_.-]/g, '')}:${slot}`, field, operator, value: value ?? null, negated: false, label: '', source: 'user', confidence: 1, suggested: false },
    ];
}

/** What the controls of one field show for a chip set. Mirrors Data\ChipSet::controls(). */
export function controlsFor(chips: Chip[], field: string): { min: unknown; max: unknown; exact: unknown; bool: boolean | null } {
    const state: { min: unknown; max: unknown; exact: unknown; bool: boolean | null } = { min: null, max: null, exact: null, bool: null };

    for (const chip of chips) {
        if (chip.field !== field || chip.suggested) {
            continue;
        }

        if (chip.operator === 'is_true' || chip.operator === 'is_false') {
            state.bool = chip.operator === 'is_true';
        } else if (chip.operator === 'between' && Array.isArray(chip.value)) {
            [state.min, state.max] = chip.value;
        } else if (slots[chip.operator]) {
            state[slots[chip.operator]!] = chip.value;
        } else {
            state.exact = Array.isArray(chip.value) ? chip.value[0] : chip.value;
        }
    }

    return state;
}

/**
 * The request parameters of a results page for a settled filter set: the
 * query text, and the chips without their display-only parts, so the URL
 * stays short. Pass it as the `data` of an Inertia reload or a query string.
 */
export function toParams(filters: SearchChipsFilters): Record<string, string | undefined> {
    const chips = filters.chips.map(({ id, field, operator, value, negated, suggested }) => ({
        id,
        field,
        operator,
        value,
        ...(negated ? { negated } : {}),
        ...(suggested ? { suggested } : {}),
    }));

    return {
        q: filters.query || undefined,
        chips: chips.length ? JSON.stringify(chips) : undefined,
        leftover: chips.length && filters.leftover ? filters.leftover : undefined,
        sort: filters.sort ? JSON.stringify({ field: filters.sort.field, direction: filters.sort.direction }) : undefined,
        page: undefined,
    };
}

/** Laravel accepts the CSRF token from the page's meta tag or from the XSRF-TOKEN cookie. */
function csrfHeaders(): Record<string, string> {
    if (typeof document === 'undefined') {
        return {};
    }

    const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;

    if (meta) {
        return { 'X-CSRF-TOKEN': meta };
    }

    const cookie = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return cookie ? { 'X-XSRF-TOKEN': decodeURIComponent(cookie[1]) } : {};
}
