import {
    appendWorkspaceContextQuery,
    getWorkspaceContextConstants,
    resolvePageWorkspaceContext,
    setActiveWorkspaceContext,
} from './context';
import { WORKSPACE_LOADING_HTML } from './busy-state';
import { handleWorkspaceError } from './error-handler';
import { workspaceFetch, workspaceFetchHeaders } from './http';

export const createFragmentLoader = ({
    host,
    busyState,
    lifecycle,
    modalContentSelector = '[data-workspace-modal-content]',
}) => {
    const getModalContent = () => host.querySelector(modalContentSelector);

    const resolveContext = (context) => {
        if (context) {
            return context;
        }

        return resolvePageWorkspaceContext()
            ?? getWorkspaceContextConstants().Dashboard
            ?? null;
    };

    const buildComponentUrl = (incidentId, component, context) => {
        const baseUrl = `/incidents/${incidentId}/components/${component}`;

        return appendWorkspaceContextQuery(baseUrl, context);
    };

    const showModal = () => {
        if (!window.bootstrap?.Modal) {
            return;
        }

        window.bootstrap.Modal.getOrCreateInstance(host).show();
    };

    const showLoadingState = (modalContent) => {
        modalContent.innerHTML = WORKSPACE_LOADING_HTML;
        busyState?.setBusy('loading');
        showModal();
    };

    const showInlineError = (modalContent, payload) => {
        modalContent.innerHTML = payload.inline?.html
            ?? '<div class="p-4 text-danger small" data-workspace-error role="alert">Unable to load this action.</div>';
    };

    const openComponent = async (incidentId, component, context = null) => {
        const resolvedContext = resolveContext(context);

        if (!incidentId || !component || !resolvedContext) {
            return false;
        }

        const modalContent = getModalContent();

        if (!modalContent) {
            return false;
        }

        if (!(await lifecycle.run('beforeOpen', incidentId, component, resolvedContext))) {
            return false;
        }

        setActiveWorkspaceContext(host, resolvedContext);
        host.dataset.workspaceIncidentId = String(incidentId);
        showLoadingState(modalContent);

        let opened = false;

        try {
            const response = await workspaceFetch(
                buildComponentUrl(incidentId, component, resolvedContext),
                {
                    headers: workspaceFetchHeaders('text/html'),
                },
            );

            if (!response.ok) {
                showInlineError(modalContent, handleWorkspaceError(null, response));
                return false;
            }

            modalContent.innerHTML = await response.text();
            opened = true;

            return true;
        } catch (error) {
            showInlineError(modalContent, handleWorkspaceError(error));
            return false;
        } finally {
            busyState?.clearBusy('loading');
            await lifecycle.run('afterOpen', incidentId, component, resolvedContext, opened);
        }
    };

    return {
        openComponent,
    };
};

export const initFragmentLoader = () => {
    const loaderRoot = document.querySelector('[data-workspace-fragment-loader]');

    if (!loaderRoot) {
        return null;
    }

    loaderRoot.dataset.workspaceFragmentLoaderInitialized = 'true';

    return loaderRoot;
};
