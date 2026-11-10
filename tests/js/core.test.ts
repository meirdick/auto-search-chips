import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createSearchChips, type Chip } from '../../resources/js/core';

const chip = (id: string, extra: Partial<Chip> = {}): Chip => ({
    id, field: id, operator: 'equals', value: 1, negated: false, label: id, source: 'rules', confidence: 1, suggested: false, ...extra,
});

type Call = { url: string; body: any };

function server(responses: Array<Record<string, unknown> | (() => Promise<Response>)>) {
    const calls: Call[] = [];
    const fetch = vi.fn(async (url: string, init: RequestInit) => {
        calls.push({ url, body: JSON.parse(init.body as string) });
        const next = responses.shift() ?? {};

        return typeof next === 'function' ? next() : new Response(JSON.stringify(next), { status: 200 });
    });

    return { calls, fetch: fetch as unknown as typeof globalThis.fetch };
}

beforeEach(() => vi.useFakeTimers());
afterEach(() => vi.useRealTimers());

describe('createSearchChips', () => {
    it('debounces typing into one parse', async () => {
        const { calls, fetch } = server([{ filters: [chip('type')], leftover: '', count: 3, refinable: false }]);
        const search = createSearchChips({ for: 'posts', fetch });

        search.setQuery('v');
        search.setQuery('videos');
        await vi.runAllTimersAsync();

        expect(calls).toHaveLength(1);
        expect(calls[0].url).toBe('/search-chips/posts/parse');
        expect(calls[0].body).toMatchObject({ query: 'videos', refine: false });
        expect(search.getState()).toMatchObject({ count: 3, status: 'idle' });
        expect(search.getState().chips).toHaveLength(1);
    });

    it('shows the rules chips first, then the AI refine', async () => {
        const { calls, fetch } = server([
            { filters: [chip('type')], count: 3, refinable: true },
            { filters: [chip('type'), chip('views')], count: 1, engine: 'system-one' },
        ]);
        const settled: number[] = [];
        const search = createSearchChips({ for: 'posts', fetch, onFilters: (filters) => settled.push(filters.chips.length) });

        search.setQuery('videos above 10k');
        await vi.runAllTimersAsync();

        expect(calls.map((call) => call.body.refine)).toEqual([false, true]);
        expect(settled).toEqual([1, 2]);
        expect(search.getState()).toMatchObject({ engine: 'system-one', count: 1, status: 'idle' });
    });

    it('drops a refine that lands after the user removed a chip', async () => {
        let release!: (value: Response) => void;
        const { fetch } = server([
            { filters: [chip('type'), chip('views')], count: 1, refinable: true },
            () => new Promise<Response>((resolve) => (release = resolve)),
            { filters: [chip('type')], count: 5 },
        ]);
        const search = createSearchChips({ for: 'posts', fetch });

        search.setQuery('videos above 10k');
        await vi.advanceTimersByTimeAsync(300);
        await search.remove('views');
        release(new Response(JSON.stringify({ filters: [chip('type'), chip('views'), chip('late')], count: 0 })));
        await vi.runAllTimersAsync();

        expect(search.getState().chips.map((c) => c.id)).toEqual(['type']);
        expect(search.getState().count).toBe(5);
    });

    it('sends the remaining chips to apply when one is removed', async () => {
        const { calls, fetch } = server([{ filters: [chip('type')], count: 9 }]);
        const search = createSearchChips({ for: 'posts', fetch, initial: { chips: [chip('type'), chip('views')], leftover: 'rockets' } });

        await search.remove('views');

        expect(calls[0].url).toBe('/search-chips/posts/apply');
        expect(calls[0].body.chips.map((c: Chip) => c.id)).toEqual(['type']);
        expect(calls[0].body.leftover).toBe('rockets');
        expect(search.getState().count).toBe(9);
    });

    it('accepts a suggested chip', async () => {
        const { calls, fetch } = server([{ filters: [chip('type')], count: 2 }]);
        const search = createSearchChips({ for: 'posts', fetch, initial: { chips: [chip('type', { suggested: true })] } });

        await search.accept('type');

        expect(calls[0].body.chips[0].suggested).toBe(false);
    });

    it('keeps the other bound when one bound of a range changes', async () => {
        const { calls, fetch } = server([{ filters: [], count: 4 }]);
        const range = chip('views', { operator: 'between', value: [1000, 50000] });
        const search = createSearchChips({ for: 'posts', fetch, initial: { chips: [range] } });

        search.setFilter('views', 'lte', 20000);
        await vi.runAllTimersAsync();

        expect(calls[0].body.chips.map((c: Chip) => [c.operator, c.value])).toEqual([['gte', 1000], ['lte', 20000]]);
    });

    it('clears without a request and reports the empty filter set', () => {
        const { calls, fetch } = server([]);
        const settled: unknown[] = [];
        const search = createSearchChips({ for: 'posts', fetch, initial: { query: 'videos', chips: [chip('type')] }, onFilters: (f) => settled.push(f) });

        search.clear();

        expect(calls).toHaveLength(0);
        expect(search.getState()).toMatchObject({ query: '', chips: [], count: null });
        expect(settled).toEqual([{ query: '', chips: [], leftover: '', sort: null, count: null }]);
    });

    it('reports a failed request as an error state', async () => {
        const { fetch } = server([() => Promise.resolve(new Response('', { status: 500 }))]);
        const search = createSearchChips({ for: 'posts', fetch });

        search.setQuery('videos');
        await vi.runAllTimersAsync();

        expect(search.getState().status).toBe('error');
    });
});
