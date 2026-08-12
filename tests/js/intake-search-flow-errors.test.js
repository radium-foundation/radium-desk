import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import * as bootstrap from 'bootstrap';
import {
    INTAKE_CREATE_MISSING_ORDER_ERROR,
    INTAKE_CREATE_SESSION_ERROR,
} from '../../resources/js/intake-create-errors';
import {
    initLegacySearchConfirmModal,
    openLegacySearchConfirmModal,
} from '../../resources/js/intake-search-flow';

describe('intake-search-flow error messages', () => {
    beforeEach(() => {
        document.body.innerHTML = `
            <div class="modal fade" id="legacySearchConfirmModal">
                <div class="modal-body">
                    <dd data-legacy-confirm-order-id></dd>
                    <select id="legacy_search_confirm_source">
                        <option value="" disabled selected>Select source</option>
                        <option value="call">Call</option>
                    </select>
                    <textarea id="legacy_search_confirm_notes"></textarea>
                    <div id="legacy_search_confirm_error" class="d-none"></div>
                </div>
                <button type="button" data-legacy-search-confirm-submit>Create Service Request</button>
            </div>
        `;

        vi.spyOn(bootstrap.Modal, 'getOrCreateInstance').mockReturnValue({
            show: vi.fn(),
            hide: vi.fn(),
        });
    });

    afterEach(() => {
        document.body.innerHTML = '';
        vi.restoreAllMocks();
    });

    it('shows missing order ID toast before submitting create request', async () => {
        const showToast = vi.fn();

        initLegacySearchConfirmModal({ showToast });
        openLegacySearchConfirmModal({
            default_source: 'call',
            create_url: '/service-requests/quick',
            legacy_preview: {
                order_id: null,
                customer_name: 'Test Customer',
            },
        });

        document.getElementById('legacy_search_confirm_notes').value = 'Customer reported an issue.';
        document.getElementById('legacy_search_confirm_source').value = 'call';
        document.querySelector('[data-legacy-search-confirm-submit]')?.click();

        expect(showToast).toHaveBeenCalledWith(INTAKE_CREATE_MISSING_ORDER_ERROR, 'danger');
    });

    it('shows session-expired toast for JSON HTTP 419 create responses', async () => {
        const showToast = vi.fn();

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: false,
            status: 419,
            headers: {
                get: (name) => (name.toLowerCase() === 'content-type' ? 'application/json' : null),
            },
            json: async () => ({ message: 'CSRF token mismatch.' }),
        }));

        initLegacySearchConfirmModal({ showToast });
        openLegacySearchConfirmModal({
            default_source: 'call',
            create_url: '/service-requests/quick',
            legacy_preview: {
                order_id: 'RD3395988',
                customer_name: 'Test Customer',
            },
        });

        document.getElementById('legacy_search_confirm_notes').value = 'Customer reported an issue.';
        document.getElementById('legacy_search_confirm_source').value = 'call';
        document.querySelector('[data-legacy-search-confirm-submit]')?.click();

        await vi.waitFor(() => {
            expect(showToast).toHaveBeenCalledWith(INTAKE_CREATE_SESSION_ERROR, 'danger');
        });
    });
});
