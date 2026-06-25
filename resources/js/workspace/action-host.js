import { workspaceFetchHeaders } from './http';

export const createActionHost = ({ host, responseHandler }) => {
    const handleSubmit = async (event) => {
        const form = event.target;

        if (!(form instanceof HTMLFormElement) || !form.matches('[data-workspace-action-form]')) {
            return;
        }

        event.preventDefault();

        const submitButton = form.querySelector('[type="submit"]');

        if (submitButton) {
            submitButton.disabled = true;
        }

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                headers: workspaceFetchHeaders(),
                body: new FormData(form),
            });

            const data = await response.json();
            await responseHandler.applyWorkspaceResponse(data, host);
        } catch (error) {
            if (responseHandler.applyWorkspaceResponse) {
                await responseHandler.applyWorkspaceResponse({
                    success: false,
                    message: 'Unable to complete this action.',
                    toast: {
                        show: true,
                        message: 'Unable to complete this action.',
                        variant: 'danger',
                    },
                }, host);
            }
        } finally {
            if (submitButton) {
                submitButton.disabled = false;
            }
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
