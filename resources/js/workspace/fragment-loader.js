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

    const buildComponentUrl = (incidentId, component, context, options = {}) => {
        const baseUrl = `/incidents/${incidentId}/components/${component}`;
        let url = appendWorkspaceContextQuery(baseUrl, context);

        if (options.communicationActionKey) {
            const parsedUrl = new URL(url, window.location.origin);
            parsedUrl.searchParams.set('key', options.communicationActionKey);
            url = `${parsedUrl.pathname}${parsedUrl.search}${parsedUrl.hash}`;
        }

        return url;
    };

    const buildBatchComponentUrl = (component, incidentIds, context) => {
        const parsedUrl = new URL(`/dashboard/components/${component}`, window.location.origin);

        if (context) {
            parsedUrl.searchParams.set('context', context);
        }

        incidentIds.forEach((incidentId) => {
            parsedUrl.searchParams.append('incident_ids[]', String(incidentId));
        });

        return `${parsedUrl.pathname}${parsedUrl.search}${parsedUrl.hash}`;
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

    const openComponent = async (incidentId, component, context = null, options = {}) => {
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
                buildComponentUrl(incidentId, component, resolvedContext, options),
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

    const openBatchComponent = async (component, incidentIds, context = null) => {
        const resolvedContext = resolveContext(context);

        if (!component || incidentIds.length === 0 || !resolvedContext) {
            return false;
        }

        const modalContent = getModalContent();

        if (!modalContent) {
            return false;
        }

        if (!(await lifecycle.run('beforeOpen', incidentIds[0], component, resolvedContext))) {
            return false;
        }

        setActiveWorkspaceContext(host, resolvedContext);
        host.dataset.workspaceIncidentId = String(incidentIds[0]);
        showLoadingState(modalContent);

        let opened = false;

        try {
            const response = await workspaceFetch(
                buildBatchComponentUrl(component, incidentIds, resolvedContext),
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
            await lifecycle.run('afterOpen', incidentIds[0], component, resolvedContext, opened);
        }
    };

    return {
        openComponent,
        openBatchComponent,
    };
};
