import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mergeServiceCaseRows } from '../../resources/js/live-dashboard-merge';

const buildDashboardCard = () => {
    document.body.innerHTML = `
        <div class="dashboard-service-cases-card">
            <div id="dashboard-service-cases-scroll">
                <table>
                    <thead><tr><th>Ref</th><th>Status</th></tr></thead>
                    <tbody id="dashboard-service-cases-body">
                        <tr id="service-case-row-1" data-incident-id="1"><td>SC00001</td><td class="status-cell">Open</td></tr>
                        <tr id="service-case-row-2" data-incident-id="2"><td>SC00002</td><td class="status-cell">Open</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    `;

    return document.querySelector('.dashboard-service-cases-card');
};

describe('mergeServiceCaseRows', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('updates unlocked rows and notifies caller during refresh merge', () => {
        const card = buildDashboardCard();
        const initTooltips = vi.fn();
        const onRowsUpdated = vi.fn();

        mergeServiceCaseRows(
            card,
            [
                {
                    incident_id: 1,
                    html: '<tr id="service-case-row-1" data-incident-id="1"><td>SC00001</td><td class="status-cell">Closed</td></tr>',
                },
            ],
            false,
            '',
            initTooltips,
            { lockedIncidentIds: [], onRowsUpdated },
        );

        expect(document.getElementById('service-case-row-1')).not.toBeNull();
        expect(document.querySelector('#service-case-row-1 .status-cell')?.textContent).toBe('Closed');
        expect(document.querySelector('#service-case-row-2')).toBeNull();
        expect(onRowsUpdated).toHaveBeenCalledWith([1]);
    });

    it('keeps locked rows when the server returns an empty queue', () => {
        const card = buildDashboardCard();

        mergeServiceCaseRows(card, [], true, '', vi.fn(), { lockedIncidentIds: [1] });

        expect(document.querySelector('#service-case-row-1')).not.toBeNull();
        expect(document.querySelector('#dashboard-service-cases-empty-row')).toBeNull();
    });

    it('ignores incoming updates for locked rows while updating unlocked rows', () => {
        const card = buildDashboardCard();
        const onRowsUpdated = vi.fn();

        mergeServiceCaseRows(
            card,
            [
                {
                    incident_id: 1,
                    html: '<tr id="service-case-row-1" data-incident-id="1"><td>SC00001</td><td class="status-cell">Closed</td></tr>',
                },
                {
                    incident_id: 2,
                    html: '<tr id="service-case-row-2" data-incident-id="2"><td>SC00002</td><td class="status-cell">Closed</td></tr>',
                },
            ],
            false,
            '',
            vi.fn(),
            { lockedIncidentIds: [1], onRowsUpdated },
        );

        expect(document.querySelector('#service-case-row-1 .status-cell')?.textContent).toBe('Open');
        expect(document.querySelector('#service-case-row-2 .status-cell')?.textContent).toBe('Closed');
        expect(onRowsUpdated).toHaveBeenCalledWith([2]);
    });
});
