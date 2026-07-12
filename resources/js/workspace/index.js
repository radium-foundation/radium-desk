import {
    getWorkspaceContextConstants,
    initWorkspaceContext,
    resolvePageWorkspaceContext,
} from './context';
import { createActionHost, initActionHost } from './action-host';
import { createBusyStateManager } from './busy-state';
import { createFragmentLoader } from './fragment-loader';
import { createLifecycleRunner } from './lifecycle';
import { createResponseHandler } from './response-handler';
import { getWorkspaceSession } from './session';

let workspaceApi = null;

const bindTriggers = (openComponent) => {
    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-workspace-trigger]');

        if (!trigger) {
            return;
        }

        event.preventDefault();

        const component = trigger.dataset.workspaceTrigger;
        const incidentId = trigger.dataset.workspaceIncidentId;
        const context = trigger.dataset.workspaceContext ?? resolvePageWorkspaceContext();
        const communicationActionKey = trigger.dataset.workspaceCommunicationActionKey ?? null;

        openComponent(incidentId, component, context, {
            communicationActionKey,
        });
    });
};

export const openWorkspaceComponent = (incidentId, component, context = null, options = {}) => {
    if (!workspaceApi) {
        return Promise.resolve(false);
    }

    return workspaceApi.openComponent(incidentId, component, context, options);
};

export const initWorkspace = (hooks = {}) => {
    initWorkspaceContext();

    const host = initActionHost();

    if (!host) {
        return null;
    }

    const lifecycle = createLifecycleRunner(hooks);
    const busyState = createBusyStateManager(host);
    const responseHandler = createResponseHandler(hooks, lifecycle);
    const fragmentLoader = createFragmentLoader({ host, busyState, lifecycle });
    const actionHost = createActionHost({ host, responseHandler, busyState, lifecycle });

    actionHost.bind();
    bindTriggers(fragmentLoader.openComponent);

    host.addEventListener('shown.bs.modal', () => {
        getWorkspaceSession().acquire('workspace-modal');
    });

    host.addEventListener('hidden.bs.modal', () => {
        getWorkspaceSession().release('workspace-modal');
        lifecycle.run('afterClose', host);
    });

    workspaceApi = {
        openComponent: fragmentLoader.openComponent,
        openBatchComponent: fragmentLoader.openBatchComponent,
        applyWorkspaceResponse: (data) => responseHandler.applyWorkspaceResponse(data, host),
        getContextConstants: getWorkspaceContextConstants,
        resolvePageContext: resolvePageWorkspaceContext,
        setBusy: (reason, form) => busyState.setBusy(reason, form),
        clearBusy: (reason, form) => busyState.clearBusy(reason, form),
        isBusy: (reason) => busyState.isBusy(reason),
        session: getWorkspaceSession(),
    };

    host.dataset.workspaceInitialized = 'true';

    return workspaceApi;
};

export { initWorkspaceContext, resolvePageWorkspaceContext, getWorkspaceContextConstants };
export { getWorkspaceSession, resetWorkspaceSession } from './session';
