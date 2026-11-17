import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    collectBatchTransactionSerials,
    initBatchTransactionForm,
} from '../../resources/js/dashboard-batch-transaction';

describe('dashboard batch transaction form', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
        vi.restoreAllMocks();
    });

    it('collects only non-empty serial numbers from the modal list', () => {
        document.body.innerHTML = `
            <form data-workspace-action-form="batch-transaction">
                <button data-batch-serial-copy data-serial="  SN-1  "></button>
                <button data-batch-serial-copy data-serial=""></button>
                <button data-batch-serial-copy data-serial="SN-2"></button>
            </form>
        `;

        const form = document.querySelector('form');
        expect(collectBatchTransactionSerials(form)).toEqual(['SN-1', 'SN-2']);
    });

    it('copy-all still copies joined serials', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        vi.stubGlobal('navigator', {
            clipboard: { writeText },
        });

        document.body.innerHTML = `
            <form data-workspace-action-form="batch-transaction">
                <button type="button" data-copy-all-serials></button>
                <button data-batch-serial-copy data-serial="A"></button>
                <button data-batch-serial-copy data-serial="B"></button>
            </form>
        `;

        const toast = vi.fn();
        initBatchTransactionForm(document.body, toast);
        document.querySelector('[data-copy-all-serials]').dispatchEvent(new MouseEvent('click', { bubbles: true }));

        await vi.waitFor(() => {
            expect(writeText).toHaveBeenCalledWith('A\nB');
        });

        await vi.waitFor(() => {
            expect(toast).toHaveBeenCalledWith('Copied 2 serial numbers');
        });
    });

    it('disables excel export button while download is in flight', async () => {
        let resolveFetch;
        const fetchPromise = new Promise((resolve) => {
            resolveFetch = resolve;
        });
        vi.stubGlobal('fetch', vi.fn(() => fetchPromise));

        document.body.innerHTML = `
            <form data-workspace-action-form="batch-transaction">
                <input type="hidden" name="workspace_context" value="dashboard" />
                <input type="hidden" name="incident_ids[]" value="10" />
                <button type="button" data-export-serials-excel data-batch-serial-export-url="/export"></button>
                <button data-batch-serial-copy data-serial="SN-1"></button>
            </form>
        `;

        initBatchTransactionForm(document.body, vi.fn());
        const exportButton = document.querySelector('[data-export-serials-excel]');
        exportButton.dispatchEvent(new MouseEvent('click', { bubbles: true }));

        await vi.waitFor(() => {
            expect(exportButton.disabled).toBe(true);
        });

        resolveFetch({
            ok: true,
            headers: {
                get: (name) => (name === 'content-disposition' ? 'attachment; filename="RDWarrantyCheck-SerialNumbers-20261117-2202.xlsx"' : ''),
            },
            blob: async () => new Blob(['excel'], {
                type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            }),
        });

        await fetchPromise;
        await vi.waitFor(() => {
            expect(exportButton.disabled).toBe(false);
        });
    });
});