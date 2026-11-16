import { describe, expect, it, vi } from 'vitest';
import { presentMorphoServiceReferencePrefill } from '../../resources/js/dashboard-morpho-service-reference-prefill';

describe('presentMorphoServiceReferencePrefill', () => {
    it('renders OEM review and opens inline editor without auto-saving', () => {
        document.body.innerHTML = `
            <table><tr>
            <td class="transaction-id-cell"
                data-inline-transaction="true"
                data-incident-id="42">
                <button type="button" class="transaction-cell-trigger">Add</button>
                <div class="transaction-inline-editor d-none">
                    <input type="text" class="transaction-inline-input" value="">
                </div>
            </td>
            </tr></table>
        `;

        const result = presentMorphoServiceReferencePrefill(42, {
            eligible: true,
            session_public_id: 'sess-1',
            oem_review: {
                whitelist_status: 'Whitelisted',
                whitelist_date: '2024-01-01',
                expiry_date: '2027-01-01',
            },
            suggested_transaction_id: null,
        });

        expect(result.eligible).toBe(true);
        expect(document.querySelector('[data-morpho-sr-prefill="42"]')).toBeTruthy();

        const openButton = document.querySelector('[data-morpho-sr-open-editor]');
        openButton?.dispatchEvent(new MouseEvent('click', { bubbles: true }));

        const editor = document.querySelector('.transaction-inline-editor');
        const input = document.querySelector('.transaction-inline-input');
        expect(editor?.classList.contains('d-none')).toBe(false);
        expect(input?.value).toBe('');
    });

    it('shows rejection copy when not eligible', () => {
        document.body.innerHTML = `
            <table><tr>
            <td class="transaction-id-cell"
                data-inline-transaction="true"
                data-incident-id="7">
                <button type="button" class="transaction-cell-trigger">Add</button>
                <div class="transaction-inline-editor d-none"></div>
            </td>
            </tr></table>
        `;

        presentMorphoServiceReferencePrefill(7, {
            eligible: false,
            rejection_reason: 'Device is valid on Morpho but is not already whitelisted.',
        });

        const panel = document.querySelector('[data-morpho-sr-prefill="7"]');
        expect(panel?.textContent).toContain('not already whitelisted');
        expect(panel?.querySelector('[data-morpho-sr-open-editor]')).toBeNull();
    });
});