import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    applyDashboardQuickFilter,
    detectSearchIntent,
    initDashboardQuickFilter,
    isUniversalSearchActive,
    resetDashboardQuickFilterState,
    SEARCH_INTENT,
    shouldRunUniversalSearch,
} from '../../resources/js/dashboard-filter';
import { resetWorkspaceSession } from '../../resources/js/workspace/session';

vi.mock('../../resources/js/live-dashboard', () => ({
    applyRows: vi.fn(),
}));

import { applyRows } from '../../resources/js/live-dashboard';

const buildDashboardCard = (searchUrl = '/dashboard/search') => {
    document.body.innerHTML = `
        <div id="dashboard-page" data-search-url="${searchUrl}">
            <div class="dashboard-service-cases-card">
                <div class="dashboard-quick-filter__control">
                    <span class="dashboard-quick-filter__icon" aria-hidden="true">
                        <i class="bi bi-search"></i>
                    </span>
                    <input type="search" data-dashboard-quick-filter-input value="">
                    <span data-dashboard-filter-count>0 / 0</span>
                </div>
                <div id="dashboard-service-cases-scroll">
                    <table>
                        <thead><tr><th>A</th><th>B</th></tr></thead>
                        <tbody id="dashboard-service-cases-body">
                            <tr id="service-case-row-1"
                                data-incident-id="1"
                                data-search-text="ord-100 sc00001 john 9876543210 sn-1 txn-1 product-a">
                                <td>ORD-100</td><td>SC00001</td>
                            </tr>
                            <tr id="service-case-row-2"
                                data-incident-id="2"
                                data-search-text="ord-200 sc00002 jane 9123456780 sn-2 product-b">
                                <td>ORD-200</td><td>SC00002</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    `;

    return document.querySelector('.dashboard-service-cases-card');
};

describe('detectSearchIntent', () => {
    it.each([
        ['9876543210', SEARCH_INTENT.STRUCTURED],
        ['9', SEARCH_INTENT.STRUCTURED],
        ['RD3434509', SEARCH_INTENT.STRUCTURED],
        ['R', SEARCH_INTENT.STRUCTURED],
        ['SC01427', SEARCH_INTENT.STRUCTURED],
        ['SC', SEARCH_INTENT.STRUCTURED],
        ['S', SEARCH_INTENT.STRUCTURED],
        ['SCN001', SEARCH_INTENT.STRUCTURED],
        ['TXN-10001', SEARCH_INTENT.STRUCTURED],
        ['SN-001', SEARCH_INTENT.STRUCTURED],
    ])('classifies structured identifier %s', (query, expectedIntent) => {
        expect(detectSearchIntent(query)).toBe(expectedIntent);
    });

    it.each([
        ['Danzo', SEARCH_INTENT.TEXT],
        ['D', SEARCH_INTENT.TEXT],
        ['support.customer@example.com', SEARCH_INTENT.TEXT],
        ['a@', SEARCH_INTENT.TEXT],
    ])('classifies text identifier %s', (query, expectedIntent) => {
        expect(detectSearchIntent(query)).toBe(expectedIntent);
    });

    it('returns null for blank input', () => {
        expect(detectSearchIntent('')).toBeNull();
        expect(detectSearchIntent('   ')).toBeNull();
    });
});

describe('shouldRunUniversalSearch', () => {
    it.each([
        ['9', true],
        ['R', true],
        ['SC', true],
        ['RD3434509', true],
        ['D', false],
        ['Da', true],
        ['Danzo', true],
    ])('returns %s -> %s', (query, expected) => {
        expect(shouldRunUniversalSearch(query)).toBe(expected);
    });
});

describe('applyDashboardQuickFilter', () => {
    beforeEach(() => {
        resetWorkspaceSession();
    });

    afterEach(() => {
        resetWorkspaceSession();
    });

    it('updates the counter for all visible rows without hiding any', () => {
        const card = buildDashboardCard();
        const countElement = document.querySelector('[data-dashboard-filter-count]');

        const result = applyDashboardQuickFilter({ card, countElement });

        expect(result).toEqual({ visibleCount: 2, totalCount: 2 });
        expect(countElement?.textContent).toBe('2 / 2');
        expect(document.querySelectorAll('.dashboard-case-row--filtered-out')).toHaveLength(0);
    });

    it('clears search match highlighting', () => {
        const card = buildDashboardCard();
        const row = document.getElementById('service-case-row-1');
        row?.classList.add('dashboard-case-row--search-match');

        applyDashboardQuickFilter({ card });

        expect(row?.classList.contains('dashboard-case-row--search-match')).toBe(false);
    });

    it('does not replace the server empty state row', () => {
        document.body.innerHTML = `
            <div class="dashboard-service-cases-card">
                <table>
                    <tbody id="dashboard-service-cases-body">
                        <tr id="dashboard-service-cases-empty-row"><td>No service cases match this filter.</td></tr>
                    </tbody>
                </table>
            </div>
        `;

        applyDashboardQuickFilter({
            card: document.querySelector('.dashboard-service-cases-card'),
        });

        expect(document.getElementById('dashboard-service-cases-empty-row')).not.toBeNull();
    });
});

describe('initDashboardQuickFilter', () => {
    beforeEach(() => {
        resetWorkspaceSession();
        resetDashboardQuickFilterState();
        vi.useFakeTimers();
        applyRows.mockReset();
        global.fetch = vi.fn();
        Object.defineProperty(window, 'location', {
            configurable: true,
            writable: true,
            value: new URL('http://localhost/dashboard'),
        });
    });

    afterEach(() => {
        resetWorkspaceSession();
        resetDashboardQuickFilterState();
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('does not filter rows locally for short queries', () => {
        buildDashboardCard('');
        const pageRoot = document.getElementById('dashboard-page');
        const onFilterApplied = vi.fn();

        initDashboardQuickFilter({ pageRoot, onFilterApplied });
        onFilterApplied.mockClear();

        const input = pageRoot.querySelector('[data-dashboard-quick-filter-input]');
        input.value = 'o';
        input.dispatchEvent(new Event('input', { bubbles: true }));

        vi.advanceTimersByTime(400);

        expect(global.fetch).not.toHaveBeenCalled();
        expect(document.querySelectorAll('.dashboard-case-row--filtered-out')).toHaveLength(0);
        expect(onFilterApplied).not.toHaveBeenCalled();
    });

    it('debounces universal search and applies server rows', async () => {
        buildDashboardCard('/dashboard/search');
        const pageRoot = document.getElementById('dashboard-page');
        const onFilterApplied = vi.fn();

        global.fetch.mockResolvedValue({
            ok: true,
            json: async () => ({
                match_count: 1,
                rows: [{
                    incident_id: 99,
                    html: '<tr id="service-case-row-99" data-incident-id="99" data-search-text="ord-100"><td>ORD-100</td></tr>',
                }],
            }),
        });

        initDashboardQuickFilter({ pageRoot, onFilterApplied });
        onFilterApplied.mockClear();

        const input = pageRoot.querySelector('[data-dashboard-quick-filter-input]');
        input.value = 'ord-100';
        input.dispatchEvent(new Event('input', { bubbles: true }));

        await vi.advanceTimersByTimeAsync(400);

        expect(global.fetch).toHaveBeenCalledWith(
            expect.stringContaining('/dashboard/search?q=ord-100'),
            expect.any(Object),
        );
        expect(applyRows).toHaveBeenCalledTimes(1);
        expect(isUniversalSearchActive()).toBe(true);
        expect(pageRoot.dataset.universalSearchActive).toBe('true');
    });

    it('runs universal search immediately when Enter is pressed', async () => {
        buildDashboardCard('/dashboard/search');
        const pageRoot = document.getElementById('dashboard-page');

        global.fetch.mockResolvedValue({
            ok: true,
            json: async () => ({
                match_count: 0,
                rows: [],
            }),
        });

        initDashboardQuickFilter({ pageRoot });

        const input = pageRoot.querySelector('[data-dashboard-quick-filter-input]');
        input.value = 'ord-100';
        input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));

        await Promise.resolve();

        expect(global.fetch).toHaveBeenCalledWith(
            expect.stringContaining('/dashboard/search?q=ord-100'),
            expect.any(Object),
        );
    });

    it('runs universal search on Enter even for a single-character query', async () => {
        buildDashboardCard('/dashboard/search');
        const pageRoot = document.getElementById('dashboard-page');

        global.fetch.mockResolvedValue({
            ok: true,
            json: async () => ({
                match_count: 0,
                rows: [],
            }),
        });

        initDashboardQuickFilter({ pageRoot });

        const input = pageRoot.querySelector('[data-dashboard-quick-filter-input]');
        input.value = 'D';
        input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));

        await Promise.resolve();

        expect(global.fetch).toHaveBeenCalledWith(
            expect.stringContaining('/dashboard/search?q=D'),
            expect.any(Object),
        );
    });

    it('shows a loading indicator while universal search is in flight', async () => {
        buildDashboardCard('/dashboard/search');
        const pageRoot = document.getElementById('dashboard-page');
        const icon = pageRoot.querySelector('.dashboard-quick-filter__icon');
        const control = pageRoot.querySelector('.dashboard-quick-filter__control');

        let resolveFetch;
        global.fetch.mockReturnValue(new Promise((resolve) => {
            resolveFetch = resolve;
        }));

        initDashboardQuickFilter({ pageRoot });

        const input = pageRoot.querySelector('[data-dashboard-quick-filter-input]');
        input.value = 'ord-100';
        input.dispatchEvent(new Event('input', { bubbles: true }));

        expect(control?.hasAttribute('aria-busy')).toBe(true);
        expect(icon?.innerHTML).toContain('spinner-border');

        await vi.advanceTimersByTimeAsync(400);
        await Promise.resolve();

        expect(global.fetch).toHaveBeenCalled();
        expect(control?.hasAttribute('aria-busy')).toBe(true);

        resolveFetch({
            ok: true,
            json: async () => ({
                match_count: 0,
                rows: [],
            }),
        });

        await Promise.resolve();
        await Promise.resolve();

        expect(control?.hasAttribute('aria-busy')).toBe(false);
        expect(icon?.innerHTML).toContain('bi-search');
    });

    it('starts structured server search from the first character', async () => {
        buildDashboardCard('/dashboard/search');
        const pageRoot = document.getElementById('dashboard-page');

        global.fetch.mockResolvedValue({
            ok: true,
            json: async () => ({
                match_count: 0,
                rows: [],
            }),
        });

        initDashboardQuickFilter({ pageRoot });

        const input = pageRoot.querySelector('[data-dashboard-quick-filter-input]');
        input.value = '9';
        input.dispatchEvent(new Event('input', { bubbles: true }));

        await vi.advanceTimersByTimeAsync(400);

        expect(global.fetch).toHaveBeenCalledWith(
            expect.stringContaining('/dashboard/search?q=9'),
            expect.any(Object),
        );
    });

    it('waits for two characters before searching customer names', async () => {
        buildDashboardCard('/dashboard/search');
        const pageRoot = document.getElementById('dashboard-page');

        initDashboardQuickFilter({ pageRoot });

        const input = pageRoot.querySelector('[data-dashboard-quick-filter-input]');
        input.value = 'D';
        input.dispatchEvent(new Event('input', { bubbles: true }));

        await vi.advanceTimersByTimeAsync(400);

        expect(global.fetch).not.toHaveBeenCalled();

        input.value = 'Da';
        input.dispatchEvent(new Event('input', { bubbles: true }));

        global.fetch.mockResolvedValue({
            ok: true,
            json: async () => ({
                match_count: 0,
                rows: [],
            }),
        });

        await vi.advanceTimersByTimeAsync(400);

        expect(global.fetch).toHaveBeenCalledWith(
            expect.stringContaining('/dashboard/search?q=Da'),
            expect.any(Object),
        );
    });

    it('clears the search input and restores the filter view', async () => {
        buildDashboardCard('/dashboard/search');
        const pageRoot = document.getElementById('dashboard-page');
        const restoreHandler = vi.fn().mockResolvedValue(undefined);
        const filter = initDashboardQuickFilter({ pageRoot });

        filter.setRestoreHandler(restoreHandler);

        global.fetch.mockResolvedValue({
            ok: true,
            json: async () => ({
                match_count: 1,
                rows: [{
                    incident_id: 1,
                    html: '<tr id="service-case-row-1" data-incident-id="1"><td>ORD-100</td></tr>',
                }],
            }),
        });

        const input = pageRoot.querySelector('[data-dashboard-quick-filter-input]');
        input.value = 'ord-100';
        input.dispatchEvent(new Event('input', { bubbles: true }));

        await vi.advanceTimersByTimeAsync(400);

        filter.clearFilter();

        await vi.runAllTimersAsync();

        expect(input.value).toBe('');
        expect(restoreHandler).toHaveBeenCalled();
        expect(isUniversalSearchActive()).toBe(false);
    });

    it('bootstraps universal search from dashboard URL q param on init', async () => {
        vi.useFakeTimers();

        Object.defineProperty(window, 'location', {
            configurable: true,
            writable: true,
            value: new URL('http://localhost/dashboard?q=9883534'),
        });

        buildDashboardCard('/dashboard/search');
        document.body.insertAdjacentHTML(
            'beforeend',
            '<input id="global-search-input" type="search" value="">',
        );

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                match_count: 1,
                rows: [{
                    incident_id: 1,
                    html: '<tr id="service-case-row-1" data-incident-id="1" data-search-text="9883534"><td>Row 1</td></tr>',
                }],
            }),
        });

        const pageRoot = document.getElementById('dashboard-page');
        initDashboardQuickFilter({ pageRoot });

        await vi.runAllTimersAsync();

        expect(pageRoot.querySelector('[data-dashboard-quick-filter-input]').value).toBe('9883534');
        expect(document.getElementById('global-search-input').value).toBe('9883534');
        expect(global.fetch).toHaveBeenCalledWith(
            expect.stringContaining('/dashboard/search?q=9883534'),
            expect.any(Object),
        );
        expect(applyRows).toHaveBeenCalledTimes(1);

        vi.useRealTimers();
    });
});
