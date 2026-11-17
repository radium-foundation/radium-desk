import { describe, expect, it, vi } from 'vitest';
import {
    clearPendingOemDraftReview,
    DESK_OEM_WINDOW_MSG,
    getOemInlineCompletionContext,
    initDashboardOemWarrantyHandoff,
    markPendingOemDraftReview,
    OEM_DRAFT_NOT_RECOVERABLE_MESSAGE,
} from '../../resources/js/dashboard-oem-warranty-handoff';

describe('initDashboardOemWarrantyHandoff', () => {
    const mountDashboardPage = (enabled = false) => {
        document.body.innerHTML = `
            <div id="dashboard-page"
                data-dashboard-oem-handoff-enabled="${enabled ? '1' : '0'}"
                data-dashboard-oem-handoff-url="/dashboard/service-cases"></div>
        `;
    };

    it('surfaces extension unavailable errors via toast', () => {
        mountDashboardPage();
        const showToast = vi.fn();
        initDashboardOemWarrantyHandoff(showToast);

        document.dispatchEvent(new CustomEvent('radium:oem-handoff-status', {
            detail: { status: 'error', code: 'extension-unavailable' },
        }));

        expect(showToast).toHaveBeenCalledTimes(1);
        expect(showToast.mock.calls[0][0]).toContain('extension');
    });

    it('surfaces content-script HANDOFF_STATUS errors via window postMessage', () => {
        mountDashboardPage();
        const showToast = vi.fn();
        initDashboardOemWarrantyHandoff(showToast);

        window.dispatchEvent(new MessageEvent('message', {
            data: {
                type: DESK_OEM_WINDOW_MSG.HANDOFF_STATUS,
                status: 'error',
                code: 'timeout-awaiting-session',
            },
            origin: window.location.origin,
            source: window,
        }));

        expect(showToast).toHaveBeenCalledTimes(1);
        expect(showToast.mock.calls[0][0]).toContain('extension v0.3.9');
    });

    it('ignores successful handoff events', () => {
        mountDashboardPage();
        const showToast = vi.fn();
        initDashboardOemWarrantyHandoff(showToast);

        document.dispatchEvent(new CustomEvent('radium:oem-handoff-status', {
            detail: { status: 'ok', code: 'handoff-started' },
        }));

        expect(showToast).not.toHaveBeenCalled();
    });

    it('calls recover endpoint for visible mantra rows on init', async () => {
        mountDashboardPage(true);
        document.body.insertAdjacentHTML('beforeend', `
            <table>
                <tr id="service-case-row-42">
                    <td class="case-serial-cell">
                        <button data-oem-warranty-check data-incident-id="42" data-oem-vendor="mantra"></button>
                    </td>
                </tr>
            </table>
        `);

        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ session: null }),
        });
        global.fetch = fetchMock;

        const showToast = vi.fn();
        initDashboardOemWarrantyHandoff(showToast);

        await new Promise((resolve) => {
            setTimeout(resolve, 0);
        });

        expect(fetchMock).toHaveBeenCalledWith(
            '/dashboard/service-cases/42/oem-handoff/recover',
            expect.objectContaining({
                credentials: 'same-origin',
            }),
        );
        expect(showToast).not.toHaveBeenCalled();
    });

    it('shows recovery guidance on 404 only when a draft was pending review', async () => {
        mountDashboardPage(true);
        document.body.insertAdjacentHTML('beforeend', `
            <table>
                <tr id="service-case-row-42">
                    <td class="case-serial-cell">
                        <button data-oem-warranty-check data-incident-id="42" data-oem-vendor="mantra"></button>
                    </td>
                </tr>
            </table>
        `);

        markPendingOemDraftReview(42);
        global.fetch = vi.fn().mockResolvedValue({
            ok: false,
            status: 404,
            json: async () => ({}),
        });

        const showToast = vi.fn();
        initDashboardOemWarrantyHandoff(showToast);

        await new Promise((resolve) => {
            setTimeout(resolve, 0);
        });

        expect(showToast).toHaveBeenCalledWith(
            OEM_DRAFT_NOT_RECOVERABLE_MESSAGE,
            { variant: 'warning' },
        );
        clearPendingOemDraftReview(42);
    });

    it('does not show recovery guidance on 404 without pending draft', async () => {
        mountDashboardPage(true);
        document.body.insertAdjacentHTML('beforeend', `
            <table>
                <tr id="service-case-row-99">
                    <td class="case-serial-cell">
                        <button data-oem-warranty-check data-incident-id="99" data-oem-vendor="mantra"></button>
                    </td>
                </tr>
            </table>
        `);

        global.fetch = vi.fn().mockResolvedValue({
            ok: false,
            status: 404,
            json: async () => ({}),
        });

        const showToast = vi.fn();
        initDashboardOemWarrantyHandoff(showToast);

        await new Promise((resolve) => {
            setTimeout(resolve, 0);
        });

        expect(showToast).not.toHaveBeenCalled();
    });

    it('does not show recovery guidance on server errors', async () => {
        mountDashboardPage(true);
        document.body.insertAdjacentHTML('beforeend', `
            <table>
                <tr id="service-case-row-42">
                    <td class="case-serial-cell">
                        <button data-oem-warranty-check data-incident-id="42" data-oem-vendor="mantra"></button>
                    </td>
                </tr>
            </table>
        `);

        markPendingOemDraftReview(42);
        global.fetch = vi.fn().mockResolvedValue({
            ok: false,
            status: 500,
            json: async () => ({}),
        });

        const showToast = vi.fn();
        initDashboardOemWarrantyHandoff(showToast);

        await new Promise((resolve) => {
            setTimeout(resolve, 0);
        });

        expect(showToast).not.toHaveBeenCalled();
        clearPendingOemDraftReview(42);
    });

    it('starts session via window postMessage from the extension content script', async () => {
        mountDashboardPage(true);
        document.body.insertAdjacentHTML('beforeend', `
            <table>
                <tr id="service-case-row-42">
                    <td class="case-serial-cell">
                        <span class="dashboard-u-serial-value">SYN6419897</span>
                    </td>
                </tr>
            </table>
        `);

        const postCalls = [];
        const originalPost = window.postMessage.bind(window);
        window.postMessage = (...args) => {
            postCalls.push(args[0]);
            return originalPost(...args);
        };

        global.fetch = vi.fn(async (url, init) => {
            if (init?.method === 'POST' && String(url).includes('/oem-handoff/sessions')) {
                return {
                    ok: true,
                    json: async () => ({
                        handoff_token: 'tok-42',
                        session_public_id: 'sess-42',
                        mantra_capture_enabled: true,
                        result_capture_enabled: true,
                    }),
                };
            }

            return {
                ok: true,
                json: async () => ({ session: { status: 'pending' } }),
            };
        });

        const showToast = vi.fn();
        initDashboardOemWarrantyHandoff(showToast);

        window.dispatchEvent(new MessageEvent('message', {
            data: {
                type: DESK_OEM_WINDOW_MSG.HANDOFF_REQUEST,
                incident_id: 42,
                oem: 'mantra',
                serial: 'SYN6419897',
            },
            origin: window.location.origin,
            source: window,
        }));

        await new Promise((resolve) => {
            setTimeout(resolve, 0);
        });

        expect(postCalls.some((msg) => msg?.type === DESK_OEM_WINDOW_MSG.SESSION_PENDING)).toBe(true);
        expect(postCalls.some((msg) => msg?.type === DESK_OEM_WINDOW_MSG.SESSION_READY)).toBe(true);
        window.postMessage = originalPost;
    });

    it('registers inline REF completion context when a draft is recovered (no serial-column panel)', async () => {
        mountDashboardPage(true);
        document.body.insertAdjacentHTML('beforeend', `
            <table>
                <tr id="service-case-row-42">
                    <td class="case-serial-cell">
                        <button data-oem-warranty-check data-incident-id="42" data-oem-vendor="mantra"></button>
                    </td>
                </tr>
            </table>
        `);

        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                session: {
                    public_id: 'sess-42',
                    status: 'draft_ready',
                    status_label: 'Awaiting administrator review',
                    draft: {
                        oem: 'mantra',
                        outcome: 'found',
                        serial_number: 'SYN6419897',
                        model: 'MFS 110',
                        warranty_expiry_date: '2027-01-15',
                        rd_expiry_date: '2026-06-01',
                    },
                },
            }),
        });

        const showToast = vi.fn();
        initDashboardOemWarrantyHandoff(showToast);

        await new Promise((resolve) => {
            setTimeout(resolve, 0);
        });

        expect(document.querySelector('[data-oem-results-panel="42"]')).toBeNull();
        expect(getOemInlineCompletionContext(42)).toMatchObject({
            sessionPublicId: 'sess-42',
            proposedRdExpiryYmd: '2026-06-01',
        });
        expect(showToast).toHaveBeenCalledWith(
            expect.stringContaining('AW-'),
            { variant: 'info' },
        );
    });

    it('rejects OEM draft postMessage when handoff token does not match active session', () => {
        mountDashboardPage(true);
        document.body.insertAdjacentHTML('beforeend', `
            <table><tr id="service-case-row-42"><td class="case-serial-cell"></td></tr></table>
        `);

        const showToast = vi.fn();
        const fetchMock = vi.fn();
        global.fetch = fetchMock;

        initDashboardOemWarrantyHandoff(showToast);

        window.dispatchEvent(new MessageEvent('message', {
            data: {
                type: 'RADESK_OEM_HANDOFF_DRAFT',
                incident_id: 42,
                handoff_token: 'known-token',
                outcome: 'found',
                rows: [],
            },
            origin: window.location.origin,
            source: window,
        }));

        expect(showToast).toHaveBeenCalledWith(
            'OEM result rejected (session mismatch).',
            { variant: 'danger' },
        );
        expect(fetchMock).not.toHaveBeenCalled();
    });
});
