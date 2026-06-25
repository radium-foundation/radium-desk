import {
    appendWorkspaceContextQuery,
    getWorkspaceContextConstants,
    resolvePageWorkspaceContext,
    setActiveWorkspaceContext,
} from './context';
import { workspaceFetchHeaders } from './http';

export const createFragmentLoader = ({ host, modalContentSelector = '[data-workspace-modal-content]' }) => {
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

    const openComponent = async (incidentId, component, context = null) => {
        const resolvedContext = resolveContext(context);

        if (!incidentId || !component || !resolvedContext) {
            return false;
        }

        const modalContent = getModalContent();

        if (!modalContent) {
            return false;
        }

        modalContent.innerHTML = '<div class="p-4 text-center text-muted small">Loading…</div>';

        setActiveWorkspaceContext(host, resolvedContext);
        host.dataset.workspaceIncidentId = String(incidentId);

        try {
            const response = await fetch(buildComponentUrl(incidentId, component, resolvedContext), {
                headers: workspaceFetchHeaders('text/html'),
            });

            if (!response.ok) {
                modalContent.innerHTML = '<div class="p-4 text-danger small">Unable to load this action.</div>';
                showModal();
                return false;
            }

            modalContent.innerHTML = await response.text();
            showModal();

            return true;
        } catch (error) {
            modalContent.innerHTML = '<div class="p-4 text-danger small">Unable to load this action.</div>';
            showModal();
            return false;
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
