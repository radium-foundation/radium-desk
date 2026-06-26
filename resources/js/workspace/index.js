import {
    getWorkspaceContextConstants,
    initWorkspaceContext,
    resolvePageWorkspaceContext,
} from './context';
import { createActionHost, initActionHost } from './action-host';
import { createBusyStateManager } from './busy-state';
import { createFragmentLoader, initFragmentLoader } from './fragment-loader';
import { createLifecycleRunner } from './lifecycle';
import { createResponseHandler } from './response-handler';

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

        openComponent(incidentId, component, context);
    });
};

export const initWorkspace = (hooks = {}) => {
    initWorkspaceContext();

    const host = initActionHost() ?? initFragmentLoader();

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

    host.addEventListener('hidden.bs.modal', () => {
        lifecycle.run('afterClose', host);
    });

    workspaceApi = {
        openComponent: fragmentLoader.openComponent,
        applyWorkspaceResponse: (data) => responseHandler.applyWorkspaceResponse(data, host),
        getContextConstants: getWorkspaceContextConstants,
        resolvePageContext: resolvePageWorkspaceContext,
        setBusy: (reason, form) => busyState.setBusy(reason, form),
        clearBusy: (reason, form) => busyState.clearBusy(reason, form),
        isBusy: (reason) => busyState.isBusy(reason),
    };

    host.dataset.workspaceInitialized = 'true';

    return workspaceApi;
};

export const getWorkspace = () => workspaceApi;

export { initWorkspaceContext, resolvePageWorkspaceContext, getWorkspaceContextConstants };
