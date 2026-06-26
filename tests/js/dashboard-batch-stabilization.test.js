import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createBatchTransactionSession } from '../../resources/js/workspace/batch-session';
import { createServiceCaseRowReplacer } from '../../resources/js/service-case-row';
import { resetWorkspaceSession } from '../../resources/js/workspace/session';

const buildDashboardDom = () => {
    document.body.innerHTML = `
        <div id="dashboard-page">
            <div class="dashboard-bulk-bar d-none" data-bulk-bar>
                <span data-bulk-count>0</span>
                <button type="button" data-batch-clear>Clear</button>
                <button type="button" data-batch-assign disabled>Assign</button>
            </div>
            <div class="dashboard-service-cases-card">
                <table>
                    <tbody id="dashboard-service-cases-body">
                        <tr id="service-case-row-1" data-incident-id="1">
                            <td><input type="checkbox" class="service-case-select" value="1"></td>
                            <td class="case-ref">SC-1</td>
                        </tr>
                        <tr id="service-case-row-2" data-incident-id="2">
                            <td><input type="checkbox" class="service-case-select" value="2"></td>
                            <td class="case-ref">SC-2</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    `;

    return {
        pageRoot: document.getElementById('dashboard-page'),
        card: document.querySelector('.dashboard-service-cases-card'),
    };
};

const buildRowHtml = (incidentId, reference) => `<tr id="service-case-row-${incidentId}" data-incident-id="${incidentId}"><td><input type="checkbox" class="service-case-select" value="${incidentId}"></td><td class="case-ref">${reference}</td></tr>`;

const createDashboardBatchSession = ({ pageRoot, card }) => {
    let batchSession;

    const replaceServiceCaseRow = createServiceCaseRowReplacer({
        initTooltips: vi.fn(),
        onRowReplaced: (incidentId) => {
            batchSession.restoreRowState(incidentId);
            batchSession.updateToolbar();
        },
    });

    batchSession = createBatchTransactionSession({
        card,
        pageRoot,
        openBatchModal: vi.fn(),
    });

    return { batchSession, replaceServiceCaseRow, card, destroy: () => batchSession.destroy?.() };
};

describe('dashboard batch stabilization', () => {
    let batchSession;
    let replaceServiceCaseRow;
    let destroy;

    beforeEach(() => {
        resetWorkspaceSession();
        const dom = buildDashboardDom();
        ({ batchSession, replaceServiceCaseRow, destroy } = createDashboardBatchSession(dom));
    });

    afterEach(() => {
        destroy?.();
        resetWorkspaceSession();
    });

    it('preserves selection state across row replacement', () => {
        const checkbox = document.querySelector('.service-case-select[value="1"]');

        checkbox.checked = true;
        batchSession.handleCheckboxChange(checkbox);

        replaceServiceCaseRow(1, buildRowHtml(1, 'SC-1-UPDATED'));

        expect(document.querySelector('.service-case-select[value="1"]').checked).toBe(true);
        expect(document.querySelector('[data-bulk-count]').textContent).toBe('1');
    });

    it('restores all row states after live refresh merge', () => {
        batchSession.handleSelectAll(true);

        document.querySelectorAll('.service-case-select').forEach((checkbox) => {
            checkbox.checked = false;
        });

        batchSession.restoreAllRowStates();

        expect(document.querySelector('.service-case-select[value="1"]').checked).toBe(true);
        expect(document.querySelector('.service-case-select[value="2"]').checked).toBe(true);
        expect(document.querySelector('[data-bulk-count]').textContent).toBe('2');
    });
});
