import { describe, expect, it, vi } from 'vitest';
import { initDashboardOemWarrantyHandoff } from '../../resources/js/dashboard-oem-warranty-handoff';

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
            'OEM draft rejected (session mismatch).',
            { variant: 'danger' },
        );
        expect(fetchMock).not.toHaveBeenCalled();
    });
});
