import {
    getWorkspaceContextConstants,
    initWorkspaceContext,
    resolvePageWorkspaceContext,
} from './context';
import { createActionHost, initActionHost } from './action-host';
import { createFragmentLoader, initFragmentLoader } from './fragment-loader';
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

    const responseHandler = createResponseHandler(hooks);
    const fragmentLoader = createFragmentLoader({ host });
    const actionHost = createActionHost({ host, responseHandler });

    actionHost.bind();
    bindTriggers(fragmentLoader.openComponent);

    workspaceApi = {
        openComponent: fragmentLoader.openComponent,
        applyWorkspaceResponse: (data) => responseHandler.applyWorkspaceResponse(data, host),
        getContextConstants: getWorkspaceContextConstants,
        resolvePageContext: resolvePageWorkspaceContext,
    };

    host.dataset.workspaceInitialized = 'true';

    return workspaceApi;
};

export const getWorkspace = () => workspaceApi;

export { initWorkspaceContext, resolvePageWorkspaceContext, getWorkspaceContextConstants };
