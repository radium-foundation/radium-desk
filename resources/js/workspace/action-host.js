import { handleWorkspaceError, isWorkspaceResponse } from './error-handler';
import { workspaceFetch, workspaceFetchHeaders } from './http';

const parseJsonResponse = async (response) => {
    try {
        return await response.json();
    } catch (error) {
        return null;
    }
};

export const createActionHost = ({ host, responseHandler, busyState, lifecycle }) => {
    const handleSubmit = async (event) => {
        const form = event.target;

        if (!(form instanceof HTMLFormElement) || !form.matches('[data-workspace-action-form]')) {
            return;
        }

        event.preventDefault();

        if (busyState?.isBusy('submit')) {
            return;
        }

        if (!(await lifecycle.run('beforeSubmit', form, host))) {
            return;
        }

        busyState?.setBusy('submit', form);

        let responseData = null;

        try {
            const response = await workspaceFetch(form.action, {
                method: 'POST',
                headers: workspaceFetchHeaders(),
                body: new FormData(form),
            });

            const data = await parseJsonResponse(response);
            responseData = response.ok && isWorkspaceResponse(data)
                ? data
                : handleWorkspaceError(null, response, data);

            await responseHandler.applyWorkspaceResponse(responseData, host);
        } catch (error) {
            responseData = handleWorkspaceError(error);
            await responseHandler.applyWorkspaceResponse(responseData, host);
        } finally {
            busyState?.clearBusy('submit', form);
            await lifecycle.run('afterSubmit', form, host, responseData);
        }
    };

    const bind = () => {
        host.addEventListener('submit', handleSubmit);
        host.dataset.workspaceActionHostInitialized = 'true';
    };

    return {
        bind,
    };
};

export const initActionHost = () => {
    const host = document.querySelector('[data-workspace-modal-host]');

    if (!host) {
        return null;
    }

    return host;
};
