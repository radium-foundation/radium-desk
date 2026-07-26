export const workspaceHooks = {
    showToast: null,
    replaceServiceCaseRow: null,
    removeServiceCaseRow: null,
    afterSuccess: null,
    afterOpen: null,
    afterClose: null,
};

export const keyboardShortcutHooks = {
    closeOpenInlineEditor: () => false,
    openDashboardQuickFilter: () => {},
    isWorkspaceSubmitBusy: () => false,
};

export let workspaceApiRef = null;

export const setWorkspaceApiRef = (api) => {
    workspaceApiRef = api;
};
