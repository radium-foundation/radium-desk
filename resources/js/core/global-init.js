import * as bootstrap from 'bootstrap';
import { initCopyableIdentifiers } from '../copyable-identifiers';
import { initIncomingCallCardHost } from '../incoming-call-card';
import { initKeyboardShortcuts } from '../keyboard';
import { initLiveNotifications } from '../live-notifications';
import { initPresenceHeartbeat } from '../presence-heartbeat';
import { getDashboardConfig } from '../dashboard-config';
import { initBatchTransactionForm } from '../dashboard-batch-transaction';
import { createServiceCaseRowReplacer } from '../service-case-row';
import { initTooltips } from '../tooltips';
import { initWorkspace } from '../workspace';
import { initActionDialog } from '../workspace/action-dialog';
import { initCommunicationCenterForm } from '../workspace/communication-center-form';
import { initCorrectCustomerDetailsDialog } from '../workspace/correct-customer-details-dialog';
import { initCorrectDeviceIdentityDialog } from '../workspace/correct-device-identity-dialog';
import { initCorrectDeviceModelDialog } from '../workspace/correct-device-model-dialog';
import { initCorrectSerialNumberDialog } from '../workspace/correct-serial-number-dialog';
import { initWorkspaceDialogShell } from '../workspace/dialog-shell';
import { initMentionTextareas } from './mention-textareas';
import { initSidebar } from './sidebar';
import { showAppToast } from './toast';
import { keyboardShortcutHooks, setWorkspaceApiRef, workspaceApiRef, workspaceHooks } from './workspace-registry';

window.bootstrap = bootstrap;

const initFlashToasts = () => {
    document.querySelectorAll('[data-toast-show]').forEach((element) => {
        bootstrap.Toast.getOrCreateInstance(element, {
            autohide: true,
            delay: 4500,
        }).show();
    });
};

const initGlobalWorkspace = () => {
    const replaceServiceCaseRowFallback = createServiceCaseRowReplacer({ initTooltips });

    const workspaceApi = initWorkspace({
        showToast: (...args) => workspaceHooks.showToast?.(...args) ?? showAppToast(...args),
        replaceServiceCaseRow: (...args) => (
            workspaceHooks.replaceServiceCaseRow?.(...args)
            ?? replaceServiceCaseRowFallback(...args)
        ),
        removeServiceCaseRow: (incidentId) => (
            workspaceHooks.removeServiceCaseRow?.(incidentId)
            ?? document.getElementById(`service-case-row-${incidentId}`)?.remove()
        ),
        initTooltips,
        initMentionTextareas,
        afterSuccess: (data) => workspaceHooks.afterSuccess?.(data),
        afterOpen: (incidentId, component, context, opened) => {
            if (!opened) {
                return;
            }

            const modalHost = document.querySelector('[data-workspace-modal-host]');
            const modalContent = document.querySelector('[data-workspace-modal-content]');

            modalHost?.classList.toggle('workspace-modal--compact', component === 'action' || component === 'remark');
            modalHost?.classList.toggle('workspace-modal--action', component === 'action');

            if (component === 'remark' || component === 'action' || component === 'resolve' || component === 'close') {
                initMentionTextareas(modalContent);
            }

            if (component === 'action') {
                initActionDialog(modalContent);
            }

            if (component === 'batch-transaction') {
                initBatchTransactionForm(modalContent, showAppToast);
                initTooltips(modalContent);
            }

            if (component === 'correct-customer-details') {
                initCorrectCustomerDetailsDialog(modalContent);
            }

            if (component === 'correct-serial-number') {
                initCorrectSerialNumberDialog(modalContent);
            }

            if (component === 'correct-device-model') {
                initCorrectDeviceModelDialog(modalContent);
            }

            if (component === 'correct-device-identity') {
                initCorrectDeviceIdentityDialog(modalContent);
            }

            if (component === 'communication-action') {
                initCommunicationCenterForm(modalContent);
            }

            initWorkspaceDialogShell(modalHost, modalContent);
            workspaceHooks.afterOpen?.(incidentId, component, context, opened);
        },
        afterClose: (host) => {
            host?.classList.remove('workspace-modal--compact');
            host?.classList.remove('workspace-modal--action');
            workspaceHooks.afterClose?.(host);
        },
    });

    setWorkspaceApiRef(workspaceApi);

    return workspaceApi;
};

export const initGlobalShell = () => {
    initSidebar();
    initFlashToasts();
    initTooltips();
    initCopyableIdentifiers(showAppToast);
    initGlobalWorkspace();

    if (!getDashboardConfig()) {
        void import('../universal-search').then(({ initUniversalSearch }) => {
            initUniversalSearch({ showToast: showAppToast, dashboardIntegration: null });
        });
    }

    initLiveNotifications();
    initKeyboardShortcuts({
        closeOpenInlineEditor: () => keyboardShortcutHooks.closeOpenInlineEditor(),
        isWorkspaceSubmitBusy: () => workspaceApiRef?.isBusy?.('submit') ?? false,
        openDashboardQuickFilter: () => keyboardShortcutHooks.openDashboardQuickFilter(),
    });
    initPresenceHeartbeat();
    initIncomingCallCardHost();
};

document.addEventListener('DOMContentLoaded', initGlobalShell);
