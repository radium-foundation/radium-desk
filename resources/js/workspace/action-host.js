export const initActionHost = () => {
    const host = document.querySelector('[data-workspace-modal-host]');

    if (!host) {
        return;
    }

    host.dataset.workspaceActionHostInitialized = 'true';
};
