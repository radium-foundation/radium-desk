import { describe, expect, it, vi } from 'vitest';
import { initDashboardOemWarrantyHandoff } from '../../resources/js/dashboard-oem-warranty-handoff';

describe('initDashboardOemWarrantyHandoff', () => {
    const mountDashboardPage = () => {
        document.body.innerHTML = '<div id="dashboard-page" data-dashboard-oem-handoff-enabled="0"></div>';
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

    it('rejects OEM draft postMessage when handoff token does not match active session', () => {
        document.body.innerHTML = `
            <div id="dashboard-page"
                data-dashboard-oem-handoff-enabled="1"
                data-dashboard-oem-handoff-url="/dashboard/service-cases"></div>
            <table><tr id="service-case-row-42"><td class="case-serial-cell"></td></tr></table>
        `;

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
