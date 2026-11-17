import { describe, expect, it } from 'vitest';
import {
    bumpInlineSaveGeneration,
    isInlineSaveGenerationCurrent,
    revertOptimisticPendingReference,
    showOptimisticPendingReference,
} from '../../resources/js/inline-service-reference-optimistic';

const buildCell = () => {
    document.body.innerHTML = `
        <table><tbody><tr>
        <td class="transaction-id-cell" data-inline-transaction="true" data-incident-id="9">
            <button type="button" class="transaction-cell-trigger">
                <i class="bi bi-plus-lg"></i>
            </button>
            <div class="transaction-inline-editor">
                <input type="text" class="transaction-inline-input" value="TXN-OLD">
                <div class="transaction-inline-error"></div>
            </div>
        </td>
        </tr></tbody></table>
    `;

    return document.querySelector('.transaction-id-cell');
};

describe('inline service reference optimistic UI', () => {
    it('shows pending value without blocking the inline input group', () => {
        const cell = buildCell();

        showOptimisticPendingReference(cell, 'TXN-NEW-1');

        expect(cell.querySelector('.transaction-inline-editor')?.classList.contains('d-none')).toBe(true);
        expect(cell.querySelector('.transaction-cell-trigger--pending')).toBeTruthy();
        expect(cell.querySelector('.transaction-pending-value')?.textContent).toBe('TXN-NEW-1');
        expect(cell.querySelector('.transaction-inline-input')?.disabled).not.toBe(true);
    });

    it('ignores stale save responses via generation counter', () => {
        const cell = buildCell();
        const first = bumpInlineSaveGeneration(cell);
        bumpInlineSaveGeneration(cell);

        expect(isInlineSaveGenerationCurrent(cell, first)).toBe(false);
        expect(isInlineSaveGenerationCurrent(cell, Number(cell.dataset.saveGeneration))).toBe(true);
    });

    it('reverts pending display and reopens the editor on failure', () => {
        const cell = buildCell();

        showOptimisticPendingReference(cell, 'TXN-FAIL');
        revertOptimisticPendingReference(cell, { reopenEditor: true, transactionId: 'TXN-FAIL' });

        expect(cell.querySelector('.transaction-inline-editor')?.classList.contains('d-none')).toBe(false);
        expect(cell.querySelector('.transaction-cell-trigger')?.classList.contains('d-none')).toBe(true);
        expect(cell.querySelector('.transaction-inline-input')?.value).toBe('TXN-FAIL');
    });
});