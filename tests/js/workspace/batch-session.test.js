import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createBatchTransactionSession } from '../../../resources/js/workspace/batch-session';
import { getWorkspaceSession, resetWorkspaceSession } from '../../../resources/js/workspace/session';

const buildDashboardDom = () => {
    document.body.innerHTML = `
        <div id="dashboard-page">
            <div class="dashboard-bulk-toolbar" data-bulk-bar>
                <span data-bulk-count>0</span>
                <button type="button" data-batch-clear>Clear</button>
                <button type="button" data-batch-assign disabled>Assign</button>
            </div>
            <div class="dashboard-service-cases-card">
                <table>
                    <thead>
                        <tr>
                            <th><input type="checkbox" data-select-all></th>
                            <th>Reference</th>
                        </tr>
                    </thead>
                    <tbody id="dashboard-service-cases-body">
                        <tr id="service-case-row-1" data-incident-id="1">
                            <td><input type="checkbox" class="service-case-select" value="1"></td>
                            <td>SC-1</td>
                        </tr>
                        <tr id="service-case-row-2" data-incident-id="2">
                            <td><input type="checkbox" class="service-case-select" value="2"></td>
                            <td>SC-2</td>
                        </tr>
                        <tr id="service-case-row-3" data-incident-id="3">
                            <td><input type="checkbox" class="service-case-select" value="3"></td>
                            <td>SC-3</td>
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

describe('createBatchTransactionSession', () => {
    let batchSession;
    let openBatchModal;
    let pageRoot;
    let card;

    beforeEach(() => {
        resetWorkspaceSession();
        openBatchModal = vi.fn();
        ({ pageRoot, card } = buildDashboardDom());

        batchSession = createBatchTransactionSession({
            card,
            pageRoot,
            openBatchModal,
        });
    });

    afterEach(() => {
        batchSession?.destroy?.();
        resetWorkspaceSession();
    });

    it('activates inline toolbar with selected count when rows are selected', () => {
        const checkbox = document.querySelector('.service-case-select[value="1"]');

        checkbox.checked = true;
        batchSession.handleCheckboxChange(checkbox);

        expect(document.querySelector('[data-bulk-bar]').classList.contains('dashboard-bulk-toolbar--active')).toBe(true);
        expect(document.querySelector('[data-bulk-count]').textContent).toBe('1');
        expect(document.querySelector('[data-batch-assign]').disabled).toBe(false);
        expect(document.getElementById('service-case-row-1').classList.contains('dashboard-case-row--selected')).toBe(true);
    });

    it('does not modify transaction id cells when rows are selected', () => {
        document.querySelector('#service-case-row-1').insertAdjacentHTML('beforeend', `
            <td class="transaction-id-cell" data-inline-transaction="true">
                <button type="button" class="transaction-cell-trigger">Click to add</button>
                <div class="transaction-inline-editor d-none">editor</div>
            </td>
        `);

        const checkbox = document.querySelector('.service-case-select[value="1"]');
        checkbox.checked = true;
        batchSession.handleCheckboxChange(checkbox);

        expect(document.querySelector('.transaction-cell-trigger').classList.contains('d-none')).toBe(false);
        expect(document.querySelector('.transaction-inline-editor').classList.contains('d-none')).toBe(true);
    });

    it('acquires bulk-selection session while rows remain selected', () => {
        const session = getWorkspaceSession();

        batchSession.handleSelectAll(true);

        expect(session.isActive('bulk-selection')).toBe(true);
        expect(session.getLockedIncidentIds()).toEqual([1, 2, 3]);
    });

    it('opens batch modal with selected incident ids via delegated toolbar click', () => {
        batchSession.handleSelectAll(true);
        document.querySelector('[data-batch-assign]').click();

        expect(openBatchModal).toHaveBeenCalledWith([1, 2, 3]);
    });

    it('preserves failed row selection after batch result handling', () => {
        batchSession.handleSelectAll(true);
        batchSession.handleBatchResult([1, 2], [{ incident_id: 3, message: 'Unable to assign transaction ID.' }]);

        expect(document.querySelector('.service-case-select[value="1"]').checked).toBe(false);
        expect(document.querySelector('.service-case-select[value="2"]').checked).toBe(false);
        expect(document.querySelector('.service-case-select[value="3"]').checked).toBe(true);
        expect(document.querySelector('[data-bulk-count]').textContent).toBe('1');
    });

    it('restores checkbox selection after row replacement', () => {
        const checkbox = document.querySelector('.service-case-select[value="2"]');
        checkbox.checked = true;
        batchSession.handleCheckboxChange(checkbox);

        checkbox.checked = false;
        batchSession.restoreRowState(2);

        expect(document.querySelector('.service-case-select[value="2"]').checked).toBe(true);
    });

    it('clears selection, visual state, and deactivates toolbar via delegated clear click', () => {
        const session = getWorkspaceSession();
        const checkbox = document.querySelector('.service-case-select[value="1"]');

        checkbox.checked = true;
        batchSession.handleCheckboxChange(checkbox);
        document.querySelector('[data-batch-clear]').click();

        expect(checkbox.checked).toBe(false);
        expect(document.getElementById('service-case-row-1').classList.contains('dashboard-case-row--selected')).toBe(false);
        expect(session.isActive('bulk-selection')).toBe(false);
        expect(document.querySelector('[data-bulk-bar]').classList.contains('dashboard-bulk-toolbar--active')).toBe(false);
    });
});
