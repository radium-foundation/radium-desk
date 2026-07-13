import { beforeEach, describe, expect, it } from 'vitest';
import {
    buildDashboardEmptyStateHtml,
    DASHBOARD_EMPTY_VARIANT,
    syncDashboardTableEmptyPresentation,
} from '../../resources/js/dashboard-empty-state';

describe('buildDashboardEmptyStateHtml', () => {
    it('renders the filtered empty state with actions', () => {
        const html = buildDashboardEmptyStateHtml({
            variant: DASHBOARD_EMPTY_VARIANT.FILTERED,
            colSpan: 10,
            showSearchAgain: true,
        });

        expect(html).toContain('No service cases found');
        expect(html).toContain('Try adjusting your search or filters.');
        expect(html).toContain('bi-search');
        expect(html).toContain('Clear Filters');
        expect(html).toContain('Search Again');
        expect(html).toContain('colspan="10"');
    });

    it('renders the caught-up empty state without actions', () => {
        const html = buildDashboardEmptyStateHtml({
            variant: DASHBOARD_EMPTY_VARIANT.CAUGHT_UP,
            colSpan: 8,
        });

        expect(html).toContain('All caught up!');
        expect(html).toContain('No service cases require attention right now.');
        expect(html).toContain('bi-inbox');
        expect(html).not.toContain('Clear Filters');
        expect(html).not.toContain('Search Again');
    });
});

describe('syncDashboardTableEmptyPresentation', () => {
    beforeEach(() => {
        document.body.innerHTML = `
            <div class="dashboard-service-cases-card">
                <div id="dashboard-service-cases-scroll" class="dashboard-cases-table-wrap">
                    <table>
                        <thead><tr><th>Ref</th><th>Status</th></tr></thead>
                        <tbody id="dashboard-service-cases-body">
                            <tr id="dashboard-service-cases-empty-row">
                                <td colspan="2" class="dashboard-service-cases-empty-cell"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    });

    it('marks the table wrap as empty when only the empty row is visible', () => {
        const card = document.querySelector('.dashboard-service-cases-card');
        const wrap = document.getElementById('dashboard-service-cases-scroll');

        syncDashboardTableEmptyPresentation(card);

        expect(wrap?.classList.contains('dashboard-cases-table-wrap--empty')).toBe(true);
    });

    it('removes the empty presentation class when visible rows exist', () => {
        const card = document.querySelector('.dashboard-service-cases-card');
        const tbody = document.getElementById('dashboard-service-cases-body');
        const wrap = document.getElementById('dashboard-service-cases-scroll');

        document.getElementById('dashboard-service-cases-empty-row')?.remove();
        tbody?.insertAdjacentHTML('beforeend', '<tr id="service-case-row-1"><td>SC00001</td><td>Open</td></tr>');

        syncDashboardTableEmptyPresentation(card);

        expect(wrap?.classList.contains('dashboard-cases-table-wrap--empty')).toBe(false);
    });
});
