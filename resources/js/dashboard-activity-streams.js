const STREAM_STORAGE_PREFIX = 'radium.dashboardActivityStream.';
const THREAD_STORAGE_PREFIX = 'radium.dashboardActivityThread.';
const FEED_CONTROLLER = Symbol('dashboardActivityStreamsController');

const readSessionFlag = (key, fallback = false) => {
    try {
        const stored = sessionStorage.getItem(key);

        if (stored === null) {
            return fallback;
        }

        return stored === '1';
    } catch {
        return fallback;
    }
};

const writeSessionFlag = (key, value) => {
    try {
        sessionStorage.setItem(key, value ? '1' : '0');
    } catch {
        // Ignore quota / private-mode failures.
    }
};

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
        label.textContent = expanded ? 'Hide' : 'History';
    }
};

const restoreStreamState = (feed) => {
    feed.querySelectorAll('[data-dashboard-activity-stream]').forEach((section) => {
        const key = section.getAttribute('data-dashboard-activity-stream');

        if (!key) {
            return;
        }

        const defaultCollapsed = section.getAttribute('data-collapsed-default') === '1';
        const collapsed = readSessionFlag(`${STREAM_STORAGE_PREFIX}${key}`, defaultCollapsed);
        setStreamCollapsed(section, collapsed);
    });
};

const restoreThreadState = (feed) => {
    feed.querySelectorAll('[data-activity-thread]').forEach((thread) => {
        const incidentId = thread.getAttribute('data-activity-thread-incident')
            ?? thread.querySelector('[data-incident-id]')?.getAttribute('data-incident-id');

        if (!incidentId) {
            setThreadExpanded(thread, false);

            return;
        }

        const expanded = readSessionFlag(`${THREAD_STORAGE_PREFIX}${incidentId}`, false);
        setThreadExpanded(thread, expanded);
    });
};

const handleFeedClick = (event) => {
    const streamToggle = event.target.closest?.('[data-dashboard-activity-stream-toggle]');

    if (streamToggle) {
        const section = streamToggle.closest('[data-dashboard-activity-stream]');
        const key = section?.getAttribute('data-dashboard-activity-stream');

        if (!section || !key) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        const nextCollapsed = !section.classList.contains('is-collapsed');
        setStreamCollapsed(section, nextCollapsed);
        writeSessionFlag(`${STREAM_STORAGE_PREFIX}${key}`, nextCollapsed);

        return;
    }

    const threadToggle = event.target.closest?.('[data-activity-thread-toggle]');

    if (threadToggle) {
        const thread = threadToggle.closest('[data-activity-thread]');

        if (!thread) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        const incidentId = thread.getAttribute('data-activity-thread-incident')
            ?? thread.querySelector('[data-incident-id]')?.getAttribute('data-incident-id');
        const nextExpanded = !thread.classList.contains('is-expanded');

        setThreadExpanded(thread, nextExpanded);

        if (incidentId) {
            writeSessionFlag(`${THREAD_STORAGE_PREFIX}${incidentId}`, nextExpanded);
        }
    }
};

export const initDashboardActivityStreams = (root) => {
    const feed = root?.querySelector?.('[data-dashboard-activity-feed]');

    if (!feed) {
        return null;
    }

    feed[FEED_CONTROLLER]?.abort();

    const controller = new AbortController();
    feed[FEED_CONTROLLER] = controller;

    restoreStreamState(feed);
    restoreThreadState(feed);

    feed.addEventListener('click', handleFeedClick, { signal: controller.signal });

    return {
        destroy: () => {
            controller.abort();

            if (feed[FEED_CONTROLLER] === controller) {
                delete feed[FEED_CONTROLLER];
            }
        },
    };
};
