import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { formatRdExpiryYmdAsAwServiceReference } from '../../resources/js/dashboard-oem-rd-expiry-prefill';
import {
    clearOemInlineCompletionContext,
    registerOemInlineCompletionFromSession,
} from '../../resources/js/dashboard-oem-inline-completion';
import {
    resetOemRefPrefillEligibilityNotificationsForTests,
    setOemRefPrefillToastHandler,
    tryPrefillOemServiceReferenceInput,
} from '../../resources/js/dashboard-oem-ref-service-prefill';

const TODAY = '2026-11-17';
const ELIGIBLE_EXPIRY = '2030-01-15';

describe('tryPrefillOemServiceReferenceInput', () => {
    beforeEach(() => {
        resetOemRefPrefillEligibilityNotificationsForTests();
        setOemRefPrefillToastHandler(null);
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
            draft: { oem: 'mantra', rd_expiry_date: ELIGIBLE_EXPIRY },
        }, '/dashboard/service-cases');
    });

    afterEach(() => {
        clearOemInlineCompletionContext(42);
        resetOemRefPrefillEligibilityNotificationsForTests();
        setOemRefPrefillToastHandler(null);
    });

    it('prefills REF input with AW-DD-MM-YYYY and caret at start when eligible', () => {
        const input = document.querySelector('.transaction-inline-input');
        input.setSelectionRange = vi.fn();
        input.focus = vi.fn();

        const applied = tryPrefillOemServiceReferenceInput(42, { todayYmd: TODAY });

        expect(applied).toBe(true);
        expect(input.value).toBe(formatRdExpiryYmdAsAwServiceReference(ELIGIBLE_EXPIRY));
        expect(input.focus).toHaveBeenCalled();
        expect(input.setSelectionRange).toHaveBeenCalledWith(0, 0);
        expect(document.querySelector('.transaction-inline-editor')?.classList.contains('d-none')).toBe(false);
    });

    it('does not prefill when Desk document is hidden (OEM tab active)', () => {
        Object.defineProperty(document, 'visibilityState', {
            configurable: true,
            get: () => 'hidden',
        });

        const applied = tryPrefillOemServiceReferenceInput(42, { focusInput: true, todayYmd: TODAY });

        expect(applied).toBe(false);
        expect(document.querySelector('.transaction-inline-input')?.value).toBe('');
    });

    it('does not overwrite unrelated existing input text', () => {
        const input = document.querySelector('.transaction-inline-input');
        input.value = 'CUSTOM-REF';

        const applied = tryPrefillOemServiceReferenceInput(42, { todayYmd: TODAY });

        expect(applied).toBe(false);
        expect(input.value).toBe('CUSTOM-REF');
    });

    it('rejects ineligible expiry with one warning and leaves input unchanged', () => {
        clearOemInlineCompletionContext(42);
        registerOemInlineCompletionFromSession(42, {
            public_id: 'sess-42',
            status: 'draft_ready',
            draft: { oem: 'mantra', rd_expiry_date: TODAY },
        }, '/dashboard/service-cases');

        const showToast = vi.fn();
        setOemRefPrefillToastHandler(showToast);
        const input = document.querySelector('.transaction-inline-input');
        input.value = 'KEEP-ME';

        expect(tryPrefillOemServiceReferenceInput(42, { todayYmd: TODAY })).toBe(false);
        expect(input.value).toBe('KEEP-ME');
        expect(showToast).toHaveBeenCalledTimes(1);

        expect(tryPrefillOemServiceReferenceInput(42, { todayYmd: TODAY })).toBe(false);
        expect(showToast).toHaveBeenCalledTimes(1);
    });

    it('prefills Morpho already-whitelisted expiry as AW reference', () => {
        clearOemInlineCompletionContext(42);
        registerOemInlineCompletionFromSession(42, {
            public_id: 'sess-morpho',
            status: 'draft_ready',
            oem: 'morpho',
            draft: {
                oem: 'morpho',
                whitelist_qualification: 'already_whitelisted',
                fields: { expiry_date: ELIGIBLE_EXPIRY },
            },
        }, '/dashboard/service-cases');

        expect(tryPrefillOemServiceReferenceInput(42, { todayYmd: TODAY })).toBe(true);
        expect(document.querySelector('.transaction-inline-input')?.value).toBe(
            formatRdExpiryYmdAsAwServiceReference(ELIGIBLE_EXPIRY),
        );
    });

    it('does not trigger save — only sets input value', () => {
        const input = document.querySelector('.transaction-inline-input');
        const fetchMock = vi.fn();
        global.fetch = fetchMock;

        tryPrefillOemServiceReferenceInput(42, { todayYmd: TODAY });

        expect(fetchMock).not.toHaveBeenCalled();
        expect(input.value).toContain('AW-');
    });
});