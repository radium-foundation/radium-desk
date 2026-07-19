const STREAM_STORAGE_PREFIX = 'radium.dashboardActivityStream.';
const THREAD_STORAGE_PREFIX = 'radium.dashboardActivityThread.';

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

const setThreadExpanded = (thread, expanded) => {
    const toggle = thread.querySelector('[data-activity-thread-toggle]');
    const history = thread.querySelector('[data-activity-thread-history]');
    const label = thread.querySelector('[data-activity-thread-toggle-label]');

    if (!toggle || !history) {
        return;
    }

    thread.classList.toggle('is-expanded', expanded);
    toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    history.hidden = !expanded;

    if (label) {
        label.textContent = expanded ? 'Collapse' : 'History';
    }
};

const initActivityThreads = (feed) => {
    feed.querySelectorAll('[data-activity-thread]').forEach((thread) => {
        const incidentId = thread.getAttribute('data-activity-thread-incident')
            ?? thread.querySelector('[data-incident-id]')?.getAttribute('data-incident-id');
        const storageKey = incidentId ? `${THREAD_STORAGE_PREFIX}${incidentId}` : null;
        const toggle = thread.querySelector('[data-activity-thread-toggle]');

        if (!toggle) {
            return;
        }

        const stored = storageKey ? sessionStorage.getItem(storageKey) : null;
        const expanded = stored === '1';
        setThreadExpanded(thread, expanded);

        toggle.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();

            const nextExpanded = !thread.classList.contains('is-expanded');
            setThreadExpanded(thread, nextExpanded);

            if (storageKey) {
                sessionStorage.setItem(storageKey, nextExpanded ? '1' : '0');
            }
        });
    });
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
        const stored = sessionStorage.getItem(`${STREAM_STORAGE_PREFIX}${key}`);
        const collapsed = stored !== null ? stored === '1' : defaultCollapsed;

        setStreamCollapsed(section, collapsed);

        toggle?.addEventListener('click', () => {
            const nextCollapsed = !section.classList.contains('is-collapsed');
            setStreamCollapsed(section, nextCollapsed);
            sessionStorage.setItem(`${STREAM_STORAGE_PREFIX}${key}`, nextCollapsed ? '1' : '0');
        });
    });

    initActivityThreads(feed);
};
