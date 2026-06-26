import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPendingDashboardRefresh } from '../../../resources/js/live-dashboard';
import { createBatchTransactionSession, MAX_CONCURRENT_SUBMISSIONS } from '../../../resources/js/workspace/batch-session';
import { getWorkspaceSession, resetWorkspaceSession } from '../../../resources/js/workspace/session';

vi.mock('../../../resources/js/live-dashboard', () => ({
    flushPendingDashboardRefresh: vi.fn(async () => {}),
}));

const buildDashboardCard = () => {
    document.body.innerHTML = `
        <div class="dashboard-service-cases-card">
            <div class="dashboard-bulk-bar d-none" data-bulk-bar>
                <span data-bulk-count>0</span>
                <span class="d-none" data-batch-progress>
                    Completed <span data-batch-completed>0</span> of <span data-batch-total>0</span>
                </span>
                <button type="button" data-batch-clear>Clear</button>
                <button type="button" data-batch-submit disabled>Submit</button>
            </div>
            <table>
                <thead>
                    <tr>
                        <th><input type="checkbox" data-select-all></th>
                        <th>Transaction</th>
                    </tr>
                </thead>
                <tbody id="dashboard-service-cases-body">
                    <tr id="service-case-row-1" data-incident-id="1">
                        <td><input type="checkbox" class="service-case-select" value="1"></td>
                        <td class="transaction-id-cell" data-inline-transaction="true" data-store-url="/orders/10/transaction" data-incident-id="1">
                            <button type="button" class="transaction-cell-trigger">Click to add</button>
                            <div class="transaction-inline-editor d-none"></div>
                            <div class="batch-transaction-editor d-none" data-batch-transaction-editor>
                                <input type="text" data-batch-transaction-input>
                                <div data-batch-status></div>
                            </div>
                        </td>
                    </tr>
                    <tr id="service-case-row-2" data-incident-id="2">
                        <td><input type="checkbox" class="service-case-select" value="2"></td>
                        <td class="transaction-id-cell" data-inline-transaction="true" data-store-url="/orders/20/transaction" data-incident-id="2">
                            <button type="button" class="transaction-cell-trigger">Click to add</button>
                            <div class="transaction-inline-editor d-none"></div>
                            <div class="batch-transaction-editor d-none" data-batch-transaction-editor>
                                <input type="text" data-batch-transaction-input>
                                <div data-batch-status></div>
                            </div>
                        </td>
                    </tr>
                    <tr id="service-case-row-3" data-incident-id="3">
                        <td><input type="checkbox" class="service-case-select" value="3"></td>
                        <td class="transaction-id-cell" data-inline-transaction="true" data-store-url="/orders/30/transaction" data-incident-id="3">
                            <button type="button" class="transaction-cell-trigger">Click to add</button>
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
    `;

    return document.querySelector('.dashboard-service-cases-card');
};

const getBatchInput = (incidentId) => document
    .querySelector(`#service-case-row-${incidentId} [data-batch-transaction-input]`);

describe('createBatchTransactionSession', () => {
    let batchSession;
    let replaceServiceCaseRow;
    let showToast;

    beforeEach(() => {
        resetWorkspaceSession();
        vi.clearAllMocks();
        vi.stubGlobal('fetch', vi.fn());

        replaceServiceCaseRow = vi.fn();
        showToast = vi.fn();

        batchSession = createBatchTransactionSession({
            card: buildDashboardCard(),
            csrfToken: () => 'test-token',
            replaceServiceCaseRow,
            showToast,
        });
    });

    afterEach(() => {
        resetWorkspaceSession();
        vi.unstubAllGlobals();
    });

    it('acquires bulk-selection when rows are selected', () => {
        const session = getWorkspaceSession();
        const checkbox = document.querySelector('.service-case-select[value="1"]');

        checkbox.checked = true;
        batchSession.handleCheckboxChange(checkbox);

        expect(session.isActive('bulk-selection')).toBe(true);
        expect(session.getLockedIncidentIds()).toEqual([1]);
        expect(document.querySelector('[data-bulk-bar]').classList.contains('d-none')).toBe(false);
    });

    it('preserves selection state via locked incident ids during polling', () => {
        const session = getWorkspaceSession();
        const checkbox1 = document.querySelector('.service-case-select[value="1"]');
        const checkbox2 = document.querySelector('.service-case-select[value="2"]');

        checkbox1.checked = true;
        checkbox2.checked = true;
        batchSession.handleCheckboxChange(checkbox1);
        batchSession.handleCheckboxChange(checkbox2);

        expect(session.getLockedIncidentIds()).toEqual([1, 2]);
        expect(getBatchInput(1).closest('[data-batch-transaction-editor]').classList.contains('d-none')).toBe(false);
        expect(getBatchInput(2).closest('[data-batch-transaction-editor]').classList.contains('d-none')).toBe(false);
    });

    it('locks submitting rows with batch-submit during polling', async () => {
        const session = getWorkspaceSession();
        let resolveFirst;

        fetch.mockImplementation((url) => new Promise((resolve) => {
            if (url.includes('/orders/10/transaction')) {
                resolveFirst = () => resolve({
                    ok: true,
                    json: async () => ({
                        incident_id: 1,
                        row_html: '<tr id="service-case-row-1"></tr>',
                        message: 'Saved',
                    }),
                });

                return;
            }

            resolve({
                ok: true,
                json: async () => ({ incident_id: 2, row_html: '<tr id="service-case-row-2"></tr>' }),
            });
        }));

        document.querySelector('.service-case-select[value="1"]').checked = true;
        document.querySelector('.service-case-select[value="2"]').checked = true;
        getBatchInput(1).value = 'TX-1';
        getBatchInput(2).value = 'TX-2';
        batchSession.handleSelectAll(true);

        const submitPromise = batchSession.submitBatch();

        await vi.waitFor(() => {
            expect(session.isActive('batch-submit')).toBe(true);
        });

        resolveFirst();
        await submitPromise;

        expect(session.isActive('batch-submit')).toBe(false);
    });

    it('submits each selected row independently with partial success', async () => {
        fetch
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    incident_id: 1,
                    row_html: '<tr id="service-case-row-1"></tr>',
                    message: 'Saved 1',
                }),
            })
            .mockResolvedValueOnce({
                ok: false,
                json: async () => ({
                    errors: { transaction_id: ['Invalid transaction ID.'] },
                }),
            });

        document.querySelector('.service-case-select[value="1"]').checked = true;
        document.querySelector('.service-case-select[value="2"]').checked = true;
        getBatchInput(1).value = 'TX-OK';
        getBatchInput(2).value = 'TX-BAD';
        batchSession.handleSelectAll(true);

        await batchSession.submitBatch();

        expect(fetch).toHaveBeenCalledTimes(2);
        expect(replaceServiceCaseRow).toHaveBeenCalledWith(1, '<tr id="service-case-row-1"></tr>');
        expect(document.querySelector('.service-case-select[value="1"]').checked).toBe(false);
        expect(document.querySelector('.service-case-select[value="2"]').checked).toBe(true);
        expect(document.querySelector('#service-case-row-2 [data-batch-status]').dataset.batchStatus).toBe('validation');
        expect(showToast).toHaveBeenCalledWith('Saved 1');
    });

    it('completes all successful submissions and flushes deferred refresh', async () => {
        fetch.mockResolvedValue({
            ok: true,
            json: async () => ({
                incident_id: 1,
                row_html: '<tr id="service-case-row-1"></tr>',
                message: 'Saved',
            }),
        });

        const checkbox1 = document.querySelector('.service-case-select[value="1"]');
        const checkbox2 = document.querySelector('.service-case-select[value="2"]');

        checkbox1.checked = true;
        checkbox2.checked = true;
        getBatchInput(1).value = 'TX-1';
        getBatchInput(2).value = 'TX-2';
        batchSession.handleCheckboxChange(checkbox1);
        batchSession.handleCheckboxChange(checkbox2);

        await batchSession.submitBatch();

        expect(flushPendingDashboardRefresh).toHaveBeenCalledTimes(1);
        expect(document.querySelector('[data-batch-completed]').textContent).toBe('2');
        expect(document.querySelector('[data-batch-total]').textContent).toBe('2');
        expect(showToast).toHaveBeenCalledWith('2 transaction IDs saved.');
    });

    it('shows validation errors for empty transaction ids without stopping other rows', async () => {
        fetch.mockResolvedValue({
            ok: true,
            json: async () => ({
                incident_id: 2,
                row_html: '<tr id="service-case-row-2"></tr>',
            }),
        });

        document.querySelector('.service-case-select[value="1"]').checked = true;
        document.querySelector('.service-case-select[value="2"]').checked = true;
        getBatchInput(2).value = 'TX-2';
        batchSession.handleSelectAll(true);

        await batchSession.submitBatch();

        expect(fetch).toHaveBeenCalledTimes(1);
        expect(document.querySelector('#service-case-row-1 [data-batch-status]').dataset.batchStatus).toBe('validation');
        expect(document.querySelector('#service-case-row-1 [data-batch-status]').textContent).toBe('Transaction ID is required.');
    });

    it('shows failed status on network errors', async () => {
        fetch.mockRejectedValue(new Error('network down'));

        document.querySelector('.service-case-select[value="1"]').checked = true;
        getBatchInput(1).value = 'TX-1';
        batchSession.handleCheckboxChange(document.querySelector('.service-case-select[value="1"]'));

        await batchSession.submitBatch();

        expect(document.querySelector('#service-case-row-1 [data-batch-status]').dataset.batchStatus).toBe('failed');
        expect(showToast).toHaveBeenCalledWith('Some transaction IDs could not be saved.', 'danger');
    });

    it('prevents duplicate submits while a batch is in flight', async () => {
        let resolveFetch;

        fetch.mockImplementation(() => new Promise((resolve) => {
            resolveFetch = () => resolve({
                ok: true,
                json: async () => ({ incident_id: 1, row_html: '<tr id="service-case-row-1"></tr>' }),
            });
        }));

        document.querySelector('.service-case-select[value="1"]').checked = true;
        getBatchInput(1).value = 'TX-1';
        batchSession.handleCheckboxChange(document.querySelector('.service-case-select[value="1"]'));

        const firstSubmit = batchSession.submitBatch();
        const secondSubmit = batchSession.submitBatch();

        expect(batchSession.isSubmitting()).toBe(true);

        resolveFetch();
        await firstSubmit;
        await secondSubmit;

        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('limits concurrent submissions to three requests', async () => {
        let inFlight = 0;
        let maxInFlight = 0;

        fetch.mockImplementation(() => new Promise((resolve) => {
            inFlight += 1;
            maxInFlight = Math.max(maxInFlight, inFlight);

            window.setTimeout(() => {
                inFlight -= 1;
                resolve({
                    ok: true,
                    json: async () => ({ incident_id: 1, row_html: '<tr id="service-case-row-1"></tr>' }),
                });
            }, 20);
        }));

        batchSession.handleSelectAll(true);
        getBatchInput(1).value = 'TX-1';
        getBatchInput(2).value = 'TX-2';
        getBatchInput(3).value = 'TX-3';

        await batchSession.submitBatch();

        expect(maxInFlight).toBeLessThanOrEqual(MAX_CONCURRENT_SUBMISSIONS);
        expect(fetch).toHaveBeenCalledTimes(3);
    });

    it('clears selection and releases session state', () => {
        const session = getWorkspaceSession();
        const checkbox = document.querySelector('.service-case-select[value="1"]');

        checkbox.checked = true;
        getBatchInput(1).value = 'TX-1';
        batchSession.handleCheckboxChange(checkbox);
        batchSession.clearSelection();

        expect(checkbox.checked).toBe(false);
        expect(getBatchInput(1).value).toBe('');
        expect(session.isActive('bulk-selection')).toBe(false);
        expect(document.querySelector('[data-bulk-bar]').classList.contains('d-none')).toBe(true);
    });
});
