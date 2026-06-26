import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mergeServiceCaseRows } from '../../resources/js/live-dashboard-merge';
import { createServiceCaseRowReplacer } from '../../resources/js/service-case-row';
import { createBatchTransactionSession } from '../../resources/js/workspace/batch-session';
import { resetWorkspaceSession } from '../../resources/js/workspace/session';

vi.mock('../../resources/js/live-dashboard', async (importOriginal) => {
    const actual = await importOriginal();

    return {
        ...actual,
        flushPendingDashboardRefresh: vi.fn(async () => {}),
    };
});

const buildDashboardCard = () => {
    document.body.innerHTML = `
        <div class="dashboard-service-cases-card">
            <div class="dashboard-bulk-bar d-none" data-bulk-bar>
                <span data-bulk-count>0</span>
            </div>
            <div id="dashboard-service-cases-scroll">
                <table>
                    <thead>
                        <tr>
                            <th><input type="checkbox" data-select-all></th>
                            <th>Ref</th>
                            <th>Transaction</th>
                        </tr>
                    </thead>
                    <tbody id="dashboard-service-cases-body">
                        <tr id="service-case-row-1" data-incident-id="1">
                            <td><input type="checkbox" class="service-case-select" value="1"></td>
                            <td class="case-ref">SC00001</td>
                            <td class="transaction-id-cell" data-inline-transaction="true" data-incident-id="1">
                                <button type="button" class="transaction-cell-trigger">Add</button>
                                <div class="transaction-inline-editor d-none"></div>
                                <div class="batch-transaction-editor d-none" data-batch-transaction-editor>
                                    <input type="text" data-batch-transaction-input>
                                    <div data-batch-status></div>
                                </div>
                            </td>
                        </tr>
                        <tr id="service-case-row-2" data-incident-id="2">
                            <td><input type="checkbox" class="service-case-select" value="2"></td>
                            <td class="case-ref">SC00002</td>
                            <td class="transaction-id-cell" data-inline-transaction="true" data-incident-id="2">
                                <button type="button" class="transaction-cell-trigger">Add</button>
                                <div class="transaction-inline-editor d-none"></div>
                                <div class="batch-transaction-editor d-none" data-batch-transaction-editor>
                                    <input type="text" data-batch-transaction-input>
                                    <div data-batch-status></div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    `;

    return document.querySelector('.dashboard-service-cases-card');
};

const buildRowHtml = (incidentId, reference) => `<tr id="service-case-row-${incidentId}" data-incident-id="${incidentId}"><td><input type="checkbox" class="service-case-select" value="${incidentId}"></td><td class="case-ref">${reference}</td><td class="transaction-id-cell" data-inline-transaction="true" data-incident-id="${incidentId}"><button type="button" class="transaction-cell-trigger">Add</button><div class="transaction-inline-editor d-none"></div><div class="batch-transaction-editor d-none" data-batch-transaction-editor><input type="text" data-batch-transaction-input><div data-batch-status></div></div></td></tr>`;

const createDashboardBatchSession = (card) => {
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
        csrfToken: () => 'token',
        replaceServiceCaseRow,
        showToast: vi.fn(),
    });

    return { batchSession, replaceServiceCaseRow, card };
};

describe('dashboard batch stabilization', () => {
    let batchSession;
    let replaceServiceCaseRow;
    let card;

    beforeEach(() => {
        resetWorkspaceSession();
        vi.clearAllMocks();
        ({ batchSession, replaceServiceCaseRow, card } = createDashboardBatchSession(buildDashboardCard()));
    });

    afterEach(() => {
        resetWorkspaceSession();
    });

    it('restores selection after replaceServiceCaseRow replaces a row', () => {
        const checkbox = document.querySelector('.service-case-select[value="1"]');
        checkbox.checked = true;
        batchSession.handleCheckboxChange(checkbox);

        replaceServiceCaseRow(1, buildRowHtml(1, 'SC00001-UPDATED'));

        expect(document.querySelector('.service-case-select[value="1"]').checked).toBe(true);
        expect(document.querySelector('[data-bulk-count]').textContent).toBe('1');
    });

    it('restores transaction values after replaceServiceCaseRow replaces a row', () => {
        const checkbox = document.querySelector('.service-case-select[value="1"]');
        checkbox.checked = true;
        batchSession.handleCheckboxChange(checkbox);
        const input = getBatchInput(1);
        input.value = 'TX-KEEP-001';
        batchSession.handleBatchInput(input);

        replaceServiceCaseRow(1, buildRowHtml(1, 'SC00001-UPDATED'));

        expect(getBatchInput(1).value).toBe('TX-KEEP-001');
    });

    it('restores toolbar count after replaceServiceCaseRow replaces multiple rows', () => {
        batchSession.handleSelectAll(true);

        replaceServiceCaseRow(1, buildRowHtml(1, 'SC00001-UPDATED'));
        replaceServiceCaseRow(2, buildRowHtml(2, 'SC00002-UPDATED'));

        expect(document.querySelector('[data-bulk-count]').textContent).toBe('2');
        expect(document.querySelector('.service-case-select[value="1"]').checked).toBe(true);
        expect(document.querySelector('.service-case-select[value="2"]').checked).toBe(true);
    });

    it('restores batch editor visibility after row replacement', () => {
        const checkbox = document.querySelector('.service-case-select[value="1"]');
        checkbox.checked = true;
        batchSession.handleCheckboxChange(checkbox);

        replaceServiceCaseRow(1, buildRowHtml(1, 'SC00001-UPDATED'));

        expect(document.querySelector('#service-case-row-1 .transaction-cell-trigger').classList.contains('d-none')).toBe(true);
        expect(document.querySelector('#service-case-row-1 [data-batch-transaction-editor]').classList.contains('d-none')).toBe(false);
    });

    it('live polling preserves selection after merge refresh', () => {
        batchSession.handleSelectAll(true);
        const input1 = getBatchInput(1);
        input1.value = 'TX-POLL-001';
        batchSession.handleBatchInput(input1);

        mergeServiceCaseRows(
            card,
            [
                { incident_id: 1, html: buildRowHtml(1, 'SC00001-POLLED') },
                { incident_id: 2, html: buildRowHtml(2, 'SC00002-POLLED') },
            ],
            false,
            '',
            vi.fn(),
            {
                onRowsUpdated: () => batchSession.restoreAllRowStates(),
            },
        );

        expect(document.querySelector('.service-case-select[value="1"]').checked).toBe(true);
        expect(document.querySelector('.service-case-select[value="2"]').checked).toBe(true);
        expect(getBatchInput(1).value).toBe('TX-POLL-001');
        expect(document.querySelector('[data-bulk-count]').textContent).toBe('2');
        expect(document.querySelector('#service-case-row-1 .case-ref').textContent).toBe('SC00001-POLLED');
    });
});

const getBatchInput = (incidentId) => document
    .querySelector(`#service-case-row-${incidentId} [data-batch-transaction-input]`);
