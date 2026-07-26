import { initSystemSettingsPerformance } from '../system-settings-performance';
import { initSystemSettingsForm } from '../system-settings-form';
import { initSystemSettingsNav } from '../system-settings-nav';
import { initSystemSettingsSearch } from '../system-settings-search';
import { initSystemSettingsConfirm } from '../system-settings-confirm';
import { initSystemSettingsSliders, initSystemSettingsTooltips } from '../system-settings-ui';
import { initRealtimeAdminActions } from '../realtime-admin-actions';

const bindLazyAuditDrawer = () => {
    const openButton = document.querySelector('[data-system-settings-audit-open]');

    if (!openButton || openButton.dataset.auditLazyBound === 'true') {
        return;
    }

    openButton.dataset.auditLazyBound = 'true';

    openButton.addEventListener('click', async (event) => {
        if (openButton.dataset.auditReady === 'true') {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();

        const { initSystemSettingsAudit } = await import('../system-settings-audit');
        openButton.dataset.auditReady = 'true';
        initSystemSettingsAudit();
        openButton.click();
    }, true);
};

document.addEventListener('DOMContentLoaded', () => {
    initSystemSettingsPerformance();
    initSystemSettingsForm();
    initSystemSettingsNav();
    initSystemSettingsSearch();
    initSystemSettingsConfirm();
    initSystemSettingsSliders();
    initSystemSettingsTooltips();
    initRealtimeAdminActions();
    bindLazyAuditDrawer();
});
