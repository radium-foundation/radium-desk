import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createBatchTransactionSession } from '../../resources/js/workspace/batch-session';

const buildProductionLikeDashboardDom = () => {
    document.body.innerHTML = `
        <div id="dashboard-page">
            <div class="card dashboard-service-cases-card">
                <div class="card-header">
                    <div class="dashboard-bulk-toolbar" data-bulk-bar>
                        <span data-bulk-idle-hint>Hint</span>
                        <span class="d-none" data-bulk-selected-label>☑ <span data-bulk-count>0</span> selected</span>
                        <button type="button" data-batch-assign disabled>Assign</button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <table>
                        <thead>
                            <tr>
                                <th><input type="checkbox" data-select-all></th>
                            </tr>
                        </thead>
                        <tbody id="dashboard-service-cases-body">
                            <tr id="service-case-row-1">
                                <td><input type="checkbox" class="service-case-select" value="1"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    `;

    return {
        pageRoot: document.getElementById('dashboard-page'),
        card: document.querySelector('.dashboard-service-cases-card'),
    };
};

describe('dashboard batch wiring', () => {
    let batchSession;
    let openBatchModal;
    let card;
    let pageRoot;

    beforeEach(() => {
        ({ pageRoot, card } = buildProductionLikeDashboardDom());
        openBatchModal = vi.fn();

        batchSession = createBatchTransactionSession({
            card,
            pageRoot,
            openBatchModal,
        });

        card.addEventListener('change', (event) => {
            if (event.target.matches('.service-case-select, [data-select-all]')) {
                if (event.target.matches('[data-select-all]')) {
                    batchSession.handleSelectAll(event.target.checked);

                    return;
                }

                batchSession.handleCheckboxChange(event.target);
            }
        });
    });

    afterEach(() => {
        batchSession?.destroy?.();
    });

    it('activates toolbar when checkbox change is delegated from the service cases card', () => {
        const checkbox = document.querySelector('.service-case-select[value="1"]');

        checkbox.dispatchEvent(new Event('change', { bubbles: true }));

        expect(checkbox.checked).toBe(false);
        expect(document.querySelector('[data-bulk-bar]').classList.contains('dashboard-bulk-toolbar--active')).toBe(false);

        checkbox.checked = true;
        checkbox.dispatchEvent(new Event('change', { bubbles: true }));

        expect(document.querySelector('[data-bulk-bar]').classList.contains('dashboard-bulk-toolbar--active')).toBe(true);
        expect(document.querySelector('[data-batch-assign]').disabled).toBe(false);
    });

    it('opens batch modal when assign is clicked after delegated selection', () => {
        const checkbox = document.querySelector('.service-case-select[value="1"]');

        checkbox.checked = true;
        checkbox.dispatchEvent(new Event('change', { bubbles: true }));
        document.querySelector('[data-batch-assign]').click();

        expect(openBatchModal).toHaveBeenCalledWith([1]);
    });
});
