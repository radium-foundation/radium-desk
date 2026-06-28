export function initOrderWorkspace() {
    const root = document.querySelector('[data-order-workspace]');

    if (!root) {
        return;
    }

    const tabs = Array.from(root.querySelectorAll('[data-workspace-tab]'));
    const panes = Array.from(root.querySelectorAll('[data-workspace-tab-pane]'));

    if (tabs.length === 0 || panes.length === 0) {
        return;
    }

    const activateTab = (tabKey) => {
        tabs.forEach((tab) => {
            const isActive = tab.dataset.workspaceTab === tabKey;
            tab.classList.toggle('active', isActive);
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        panes.forEach((pane) => {
            const isActive = pane.dataset.workspaceTabPane === tabKey;
            pane.classList.toggle('d-none', !isActive);

            if (isActive) {
                pane.dataset.loaded = 'true';
            }
        });
    };

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            activateTab(tab.dataset.workspaceTab);
        });
    });

    const initialTab = tabs.find((tab) => tab.classList.contains('active'))?.dataset.workspaceTab ?? 'overview';
    activateTab(initialTab);
}
