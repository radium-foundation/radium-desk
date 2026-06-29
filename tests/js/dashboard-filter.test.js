import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    applyDashboardQuickFilter,
    detectSearchIntent,
    initDashboardQuickFilter,
    isUniversalSearchActive,
    SEARCH_INTENT,
    shouldRunUniversalSearch,
} from '../../resources/js/dashboard-filter';
import { getWorkspaceSession, resetWorkspaceSession } from '../../resources/js/workspace/session';

vi.mock('../../resources/js/live-dashboard', () => ({
    applyRows: vi.fn(),
}));

import { applyRows } from '../../resources/js/live-dashboard';

const buildDashboardCard = (searchUrl = '/dashboard/search') => {
    document.body.innerHTML = `
        <div id="dashboard-page" data-search-url="${searchUrl}">
            <div class="dashboard-service-cases-card">
                <input type="search" data-dashboard-quick-filter-input value="">
                <span data-dashboard-filter-count>0 / 0</span>
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

    it('shows all rows and updates the counter when the query is empty', () => {
        const card = buildDashboardCard();
        const countElement = document.querySelector('[data-dashboard-filter-count]');

        const result = applyDashboardQuickFilter({ card, query: '', countElement });

        expect(result).toEqual({ visibleCount: 2, totalCount: 2 });
        expect(countElement?.textContent).toBe('2 / 2');
        expect(document.querySelectorAll('.dashboard-case-row--filtered-out')).toHaveLength(0);
    });

    it('hides non-matching rows without removing them from the DOM', () => {
        const card = buildDashboardCard();

        applyDashboardQuickFilter({ card, query: 'ord-100' });

        expect(document.getElementById('service-case-row-1')?.classList.contains('dashboard-case-row--filtered-out')).toBe(false);
        expect(document.getElementById('service-case-row-2')?.classList.contains('dashboard-case-row--filtered-out')).toBe(true);
        expect(document.querySelectorAll('tr[id^="service-case-row-"]')).toHaveLength(2);
    });

    it('shows the quick-filter empty row when nothing matches', () => {
        const card = buildDashboardCard();

        applyDashboardQuickFilter({ card, query: 'missing-value' });

        const emptyRow = document.getElementById('dashboard-quick-filter-empty-row');

        expect(emptyRow?.classList.contains('d-none')).toBe(false);
        expect(emptyRow?.textContent).toContain('No matching rows.');
        expect(emptyRow?.textContent).toContain('Clear filter');
    });

    it('does not hide rows with an active inline transaction editor', () => {
        const card = buildDashboardCard();
        const row = document.getElementById('service-case-row-2');

        row?.insertAdjacentHTML('beforeend', `
            <td colspan="2">
                <div class="transaction-inline-editor">Editing</div>
            </td>
        `);

        applyDashboardQuickFilter({ card, query: 'missing-value' });

        expect(row?.classList.contains('dashboard-case-row--filtered-out')).toBe(false);
    });

    it('does not hide locked rows during an active workspace session', () => {
        const card = buildDashboardCard();
        const session = getWorkspaceSession();

        session.acquire('inline-transaction', { incidentId: 2 });

        applyDashboardQuickFilter({ card, query: 'missing-value' });

        expect(document.getElementById('service-case-row-2')?.classList.contains('dashboard-case-row--filtered-out')).toBe(false);
    });

    it('highlights a single visible match', () => {
        const card = buildDashboardCard();

        applyDashboardQuickFilter({ card, query: 'ord-100' });

        const row = document.getElementById('service-case-row-1');

        expect(row?.classList.contains('dashboard-case-row--search-match')).toBe(true);
        expect(document.getElementById('service-case-row-2')?.classList.contains('dashboard-case-row--search-match')).toBe(false);
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
            query: 'anything',
        });

        expect(document.getElementById('dashboard-service-cases-empty-row')).not.toBeNull();
        expect(document.getElementById('dashboard-quick-filter-empty-row')).toBeNull();
    });
});

describe('initDashboardQuickFilter', () => {
    beforeEach(() => {
        resetWorkspaceSession();
        vi.useFakeTimers();
        applyRows.mockReset();
        global.fetch = vi.fn();
    });

    afterEach(() => {
        resetWorkspaceSession();
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('debounces local filter for short queries', () => {
        buildDashboardCard('');
        const pageRoot = document.getElementById('dashboard-page');
        const onFilterApplied = vi.fn();

        initDashboardQuickFilter({ pageRoot, onFilterApplied });
        onFilterApplied.mockClear();

        const input = pageRoot.querySelector('[data-dashboard-quick-filter-input]');
        input.value = 'o';
        input.dispatchEvent(new Event('input', { bubbles: true }));

        expect(onFilterApplied).not.toHaveBeenCalled();

        vi.advanceTimersByTime(150);

        expect(onFilterApplied).toHaveBeenCalledTimes(1);
        expect(onFilterApplied.mock.calls[0][0]).toEqual({ visibleCount: 2, totalCount: 2 });
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

        await vi.advanceTimersByTimeAsync(300);

        expect(global.fetch).toHaveBeenCalledWith(
            expect.stringContaining('/dashboard/search?q=ord-100'),
            expect.any(Object),
        );
        expect(applyRows).toHaveBeenCalledTimes(1);
        expect(isUniversalSearchActive()).toBe(true);
        expect(pageRoot.dataset.universalSearchActive).toBe('true');
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

        await vi.advanceTimersByTimeAsync(300);

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

        await vi.advanceTimersByTimeAsync(300);

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

        await vi.advanceTimersByTimeAsync(300);

        expect(global.fetch).toHaveBeenCalledWith(
            expect.stringContaining('/dashboard/search?q=Da'),
            expect.any(Object),
        );
    });

    it('clears the filter from the empty-state action', async () => {
        buildDashboardCard('');
        const pageRoot = document.getElementById('dashboard-page');
        const filter = initDashboardQuickFilter({ pageRoot });
        const input = pageRoot.querySelector('[data-dashboard-quick-filter-input]');

        input.value = 'missing-value';
        filter.reapply();

        pageRoot.querySelector('[data-dashboard-quick-filter-clear]')?.click();

        await vi.runAllTimersAsync();

        expect(input.value).toBe('');
        expect(document.querySelectorAll('.dashboard-case-row--filtered-out')).toHaveLength(0);
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
