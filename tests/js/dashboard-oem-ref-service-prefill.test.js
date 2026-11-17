import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    clearOemInlineCompletionContext,
    getOemInlineCompletionContext,
    registerOemInlineCompletionFromSession,
} from '../../resources/js/dashboard-oem-inline-completion';
import { tryPrefillOemServiceReferenceInput } from '../../resources/js/dashboard-oem-ref-service-prefill';

describe('tryPrefillOemServiceReferenceInput', () => {
    beforeEach(() => {
        Object.defineProperty(document, 'visibilityState', {
            configurable: true,
            get: () => 'visible',
        });
        clearOemInlineCompletionContext(42);
        document.body.innerHTML = `
            <table class="dashboard-cases-table">
                <tbody>
                    <tr id="service-case-row-42">
                        <td class="transaction-id-cell" data-inline-transaction="true" data-incident-id="42" data-order-id="1">
                            <button type="button" class="transaction-cell-trigger"></button>
                            <div class="transaction-inline-editor d-none">
                                <input type="text" class="transaction-inline-input" value="">
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        `;
        registerOemInlineCompletionFromSession(42, {
            public_id: 'sess-42',
            status: 'draft_ready',
            draft: { oem: 'mantra', rd_expiry_date: '2027-01-29' },
        }, '/dashboard/service-cases');
    });

    afterEach(() => {
        clearOemInlineCompletionContext(42);
    });

    it('prefills REF input with d/m/Y and caret at start when Desk tab is visible', () => {
        const input = document.querySelector('.transaction-inline-input');
        input.setSelectionRange = vi.fn();
        input.focus = vi.fn();

        expect(getOemInlineCompletionContext(42)?.proposedRdExpiryDisplay).toBe('29/01/2027');
        expect(document.getElementById('service-case-row-42')).not.toBeNull();

        const applied = tryPrefillOemServiceReferenceInput(42);

        expect(applied).toBe(true);
        expect(input.value).toBe('29/01/2027');
        expect(input.focus).toHaveBeenCalled();
        expect(input.setSelectionRange).toHaveBeenCalledWith(0, 0);
        expect(document.querySelector('.transaction-inline-editor')?.classList.contains('d-none')).toBe(false);
    });

    it('does not prefill when Desk document is hidden (OEM tab active)', () => {
        Object.defineProperty(document, 'visibilityState', {
            configurable: true,
            get: () => 'hidden',
        });

        const applied = tryPrefillOemServiceReferenceInput(42, { focusInput: true });

        expect(applied).toBe(false);
        expect(document.querySelector('.transaction-inline-input')?.value).toBe('');
    });

    it('does not overwrite unrelated existing input text', () => {
        const input = document.querySelector('.transaction-inline-input');
        input.value = 'CUSTOM-REF';

        const applied = tryPrefillOemServiceReferenceInput(42);

        expect(applied).toBe(false);
        expect(input.value).toBe('CUSTOM-REF');
    });
});