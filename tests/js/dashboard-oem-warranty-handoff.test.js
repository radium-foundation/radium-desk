import { describe, expect, it, vi } from 'vitest';
import { initDashboardOemWarrantyHandoff } from '../../resources/js/dashboard-oem-warranty-handoff';

describe('initDashboardOemWarrantyHandoff', () => {
    it('surfaces extension unavailable errors via toast', () => {
        const showToast = vi.fn();
        initDashboardOemWarrantyHandoff(showToast);

        document.dispatchEvent(new CustomEvent('radium:oem-handoff-status', {
            detail: { status: 'error', code: 'extension-unavailable' },
        }));

        expect(showToast).toHaveBeenCalledTimes(1);
        expect(showToast.mock.calls[0][0]).toContain('extension');
    });

    it('ignores successful handoff events', () => {
        const showToast = vi.fn();
        initDashboardOemWarrantyHandoff(showToast);

        document.dispatchEvent(new CustomEvent('radium:oem-handoff-status', {
            detail: { status: 'ok', code: 'handoff-started' },
        }));

        expect(showToast).not.toHaveBeenCalled();
    });
});
