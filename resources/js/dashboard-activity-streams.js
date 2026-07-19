const STORAGE_PREFIX = 'radium.dashboardActivityStream.';

const setStreamCollapsed = (section, collapsed) => {
    const toggle = section.querySelector('[data-dashboard-activity-stream-toggle]');
    const panel = section.querySelector('[data-dashboard-activity-stream-panel]');

    if (!toggle || !panel) {
        return;
    }

    section.classList.toggle('is-collapsed', collapsed);
    toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    panel.hidden = collapsed;
};

export const initDashboardActivityStreams = (root) => {
    const feed = root?.querySelector?.('[data-dashboard-activity-feed]');

    if (!feed) {
        return;
    }

    feed.querySelectorAll('[data-dashboard-activity-stream]').forEach((section) => {
        const key = section.getAttribute('data-dashboard-activity-stream');

        if (!key) {
            return;
        }

        const toggle = section.querySelector('[data-dashboard-activity-stream-toggle]');
        const defaultCollapsed = section.getAttribute('data-collapsed-default') === '1';
        const stored = sessionStorage.getItem(`${STORAGE_PREFIX}${key}`);
        const collapsed = stored !== null ? stored === '1' : defaultCollapsed;

        setStreamCollapsed(section, collapsed);

        toggle?.addEventListener('click', () => {
            const nextCollapsed = !section.classList.contains('is-collapsed');

            setStreamCollapsed(section, nextCollapsed);
            sessionStorage.setItem(`${STORAGE_PREFIX}${key}`, nextCollapsed ? '1' : '0');
        });
    });
};
