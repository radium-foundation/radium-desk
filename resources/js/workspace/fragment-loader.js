export const initFragmentLoader = () => {
    const loaderRoot = document.querySelector('[data-workspace-fragment-loader]');

    if (!loaderRoot) {
        return;
    }

    loaderRoot.dataset.workspaceFragmentLoaderInitialized = 'true';
};
