import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    applyDashboardQuickFilter,
    initDashboardQuickFilter,
} from '../../resources/js/dashboard-filter';
import { getWorkspaceSession, resetWorkspaceSession } from '../../resources/js/workspace/session';

const buildDashboardCard = () => {
    document.body.innerHTML = `
        <div id="dashboard-page">
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
    });

    afterEach(() => {
        resetWorkspaceSession();
        vi.useRealTimers();
    });

    it('debounces input and calls onFilterApplied', () => {
        buildDashboardCard();
        const pageRoot = document.getElementById('dashboard-page');
        const onFilterApplied = vi.fn();

        initDashboardQuickFilter({ pageRoot, onFilterApplied });
        onFilterApplied.mockClear();

        const input = pageRoot.querySelector('[data-dashboard-quick-filter-input]');
        input.value = 'ord-100';
        input.dispatchEvent(new Event('input', { bubbles: true }));

        expect(onFilterApplied).not.toHaveBeenCalled();

        vi.advanceTimersByTime(200);

        expect(onFilterApplied).toHaveBeenCalledTimes(1);
        expect(onFilterApplied.mock.calls[0][0]).toEqual({ visibleCount: 1, totalCount: 2 });
    });

    it('clears the filter from the empty-state action', () => {
        buildDashboardCard();
        const pageRoot = document.getElementById('dashboard-page');
        const filter = initDashboardQuickFilter({ pageRoot });
        const input = pageRoot.querySelector('[data-dashboard-quick-filter-input]');

        input.value = 'missing-value';
        filter.reapply();

        pageRoot.querySelector('[data-dashboard-quick-filter-clear]')?.click();

        expect(input.value).toBe('');
        expect(document.querySelectorAll('.dashboard-case-row--filtered-out')).toHaveLength(0);
    });
});
