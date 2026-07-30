const PANEL_CONTROLLER = Symbol('dashboardTeamActivityController');
const PANEL_COLLAPSED_KEY = 'radium.teamActivityPanel.collapsed';
const EXPANDED_AGENTS_KEY = 'radium.teamActivityPanel.expandedAgents';

let refreshInFlight = false;
let pollTimeoutId = null;
let lastUserActivityAt = Date.now();
let activityListenersBound = false;
let activeController = null;

const USER_ACTIVITY_EVENTS = ['mousedown', 'keydown', 'touchstart', 'scroll'];

const readCollapsed = () => {
    try {
        const stored = sessionStorage.getItem(PANEL_COLLAPSED_KEY);

        if (stored === null) {
            return true;
        }

        return stored === '1';
    } catch {
        return true;
    }
};

const writeCollapsed = (collapsed) => {
    try {
        sessionStorage.setItem(PANEL_COLLAPSED_KEY, collapsed ? '1' : '0');
    } catch {
        // Ignore quota / private-mode failures.
    }
};

const readExpandedAgentIds = () => {
    try {
        const raw = sessionStorage.getItem(EXPANDED_AGENTS_KEY);

        if (!raw) {
            return [];
        }

        const parsed = JSON.parse(raw);

        if (!Array.isArray(parsed)) {
            return [];
        }

        return parsed.map((id) => Number(id)).filter((id) => Number.isFinite(id) && id >= 0);
    } catch {
        return [];
    }
};

const writeExpandedAgentIds = (ids) => {
    try {
        sessionStorage.setItem(EXPANDED_AGENTS_KEY, JSON.stringify(ids));
    } catch {
        // Ignore quota / private-mode failures.
    }
};

const readPollIntervalMs = (panel) => {
    const intervalMs = Number(panel?.dataset.teamActivityPollIntervalMs ?? 30000);

    return intervalMs > 0 ? intervalMs : 30000;
};

const readUserIdleMs = (panel) => {
    const idleMs = Number(panel?.dataset.teamActivityUserIdleMs ?? 300000);

    return idleMs > 0 ? idleMs : 300000;
};

const recordUserActivity = () => {
    lastUserActivityAt = Date.now();
};

const bindUserActivityListeners = () => {
    if (activityListenersBound) {
        return;
    }

    USER_ACTIVITY_EVENTS.forEach((eventName) => {
        window.addEventListener(eventName, recordUserActivity, { passive: true });
    });

    activityListenersBound = true;
};

const isUserActive = (panel) => (Date.now() - lastUserActivityAt) < readUserIdleMs(panel);

const isPanelCollapsed = (panel) => (
    panel.dataset.teamActivityCollapsed === '1' || panel.classList.contains('is-collapsed')
);

const clearPollTimeout = () => {
    if (pollTimeoutId !== null) {
        window.clearTimeout(pollTimeoutId);
        pollTimeoutId = null;
    }
};

const collectExpandedFromDom = (panel) => Array.from(
    panel.querySelectorAll('[data-team-activity-agent][data-team-activity-expanded="1"]'),
).map((row) => Number(row.getAttribute('data-team-activity-agent')))
    .filter((id) => Number.isFinite(id) && id >= 0);

const setPanelCollapsed = (panel, collapsed) => {
    panel.classList.toggle('is-collapsed', collapsed);
    panel.dataset.teamActivityCollapsed = collapsed ? '1' : '0';

    const toggle = panel.querySelector('[data-team-activity-panel-toggle]');
    toggle?.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    toggle?.setAttribute('aria-label', collapsed ? 'Expand Team Activity' : 'Collapse Team Activity');

    writeCollapsed(collapsed);
};

const isInteractiveRowTarget = (target) => {
    if (!(target instanceof Element)) {
        return false;
    }

    return Boolean(
        target.closest([
            'a[href]',
            'button',
            'input',
            'textarea',
            'select',
            'label',
            '[role="menuitem"]',
            '[data-bs-toggle]',
            '[data-dropdown]',
            'summary',
        ].join(', '))
    );
};

const hasTextSelection = () => {
    const selection = window.getSelection?.();

    if (!selection || selection.isCollapsed) {
        return false;
    }

    return selection.toString().trim().length > 0;
};

const ROW_DRAG_THRESHOLD_PX = 4;

/**
 * Only suppress row toggle when the user dragged far enough to select text.
 * A plain click on selectable text must still expand — getSelection() often
 * reports a non-empty range after tiny pointer movement on user-select:text.
 */
const wasTextDragSelect = (origin, event, rowToggle) => {
    if (!origin || origin.toggle !== rowToggle) {
        return false;
    }

    const dragged = Math.abs(event.clientX - origin.x) > ROW_DRAG_THRESHOLD_PX
        || Math.abs(event.clientY - origin.y) > ROW_DRAG_THRESHOLD_PX;

    return dragged && hasTextSelection();
};

const toggleRowExpanded = (panel, row, pageRoot) => {
    const nextExpanded = row.dataset.teamActivityExpanded !== '1';
    setRowExpanded(row, nextExpanded);
    writeExpandedAgentIds(collectExpandedFromDom(panel));
    recordUserActivity();

    if (nextExpanded) {
        void refreshTeamActivity(pageRoot, panel);
    }
};

const setRowExpanded = (row, expanded) => {
    row.classList.toggle('is-expanded', expanded);
    row.dataset.teamActivityExpanded = expanded ? '1' : '0';

    const toggle = row.querySelector('[data-team-activity-row-toggle]');
    const history = row.querySelector('[data-team-activity-history]');

    toggle?.setAttribute('aria-expanded', expanded ? 'true' : 'false');

    if (history) {
        history.hidden = !expanded;
    }
};

const restoreExpandedRows = (panel) => {
    const expandedIds = new Set(readExpandedAgentIds());

    panel.querySelectorAll('[data-team-activity-agent]').forEach((row) => {
        const id = Number(row.getAttribute('data-team-activity-agent'));
        const shouldExpand = expandedIds.has(id) || row.dataset.teamActivityExpanded === '1';

        setRowExpanded(row, shouldExpand);
    });

    writeExpandedAgentIds(collectExpandedFromDom(panel));
};

const applyPanelHtml = (pageRoot, panel, html) => {
    const template = document.createElement('template');
    template.innerHTML = html.trim();

    const nextPanel = template.content.querySelector('[data-team-activity-panel]');

    if (!nextPanel) {
        return;
    }

    const wasCollapsed = isPanelCollapsed(panel);

    panel.replaceWith(nextPanel);
    setPanelCollapsed(nextPanel, wasCollapsed);
    restoreExpandedRows(nextPanel);
    bindPanelInteractions(pageRoot, nextPanel);
};

const refreshTeamActivity = async (pageRoot, panel) => {
    const refreshUrl = panel.dataset.teamActivityRefreshUrl;

    if (!refreshUrl || document.hidden || refreshInFlight || isPanelCollapsed(panel) || !isUserActive(panel)) {
        return;
    }

    refreshInFlight = true;

    try {
        const expandedIds = collectExpandedFromDom(panel);
        const url = new URL(refreshUrl, window.location.origin);

        expandedIds.forEach((id) => {
            url.searchParams.append('expanded[]', String(id));
        });

        const response = await fetch(url.toString(), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            return;
        }

        const data = await response.json();
        const currentPanel = pageRoot.querySelector('[data-team-activity-panel]');

        if (!currentPanel) {
            return;
        }

        if (data.empty || !data.html) {
            currentPanel.remove();

            return;
        }

        if (data.html === currentPanel.outerHTML) {
            return;
        }

        applyPanelHtml(pageRoot, currentPanel, data.html);
    } catch {
        // Ignore transient network errors during background refresh.
    } finally {
        refreshInFlight = false;
    }
};

const scheduleNextPoll = (pageRoot, controller) => {
    if (controller.signal.aborted) {
        return;
    }

    clearPollTimeout();

    const panel = pageRoot.querySelector('[data-team-activity-panel]');

    if (!panel || isPanelCollapsed(panel)) {
        return;
    }

    pollTimeoutId = window.setTimeout(async () => {
        pollTimeoutId = null;

        if (controller.signal.aborted) {
            return;
        }

        const activePanel = pageRoot.querySelector('[data-team-activity-panel]');

        if (!activePanel || isPanelCollapsed(activePanel)) {
            return;
        }

        await refreshTeamActivity(pageRoot, activePanel);
        scheduleNextPoll(pageRoot, controller);
    }, readPollIntervalMs(panel));
};

const startPolling = (pageRoot, controller) => {
    clearPollTimeout();
    scheduleNextPoll(pageRoot, controller);
};

const bindPanelInteractions = (pageRoot, panel) => {
    if (panel.dataset.teamActivityBound === '1') {
        return;
    }

    panel.dataset.teamActivityBound = '1';

    let rowPointerOrigin = null;

    panel.addEventListener('pointerdown', (event) => {
        if (event.button !== 0) {
            rowPointerOrigin = null;

            return;
        }

        const rowToggle = event.target.closest?.('[data-team-activity-row-toggle]');

        if (!rowToggle || !panel.contains(rowToggle) || isInteractiveRowTarget(event.target)) {
            rowPointerOrigin = null;

            return;
        }

        rowPointerOrigin = {
            x: event.clientX,
            y: event.clientY,
            toggle: rowToggle,
        };
    });

    panel.addEventListener('click', (event) => {
        const panelToggle = event.target.closest?.('[data-team-activity-panel-toggle]');

        if (panelToggle) {
            event.preventDefault();
            const nextCollapsed = !panel.classList.contains('is-collapsed');
            setPanelCollapsed(panel, nextCollapsed);

            if (nextCollapsed) {
                clearPollTimeout();
            } else if (activeController && !activeController.signal.aborted) {
                recordUserActivity();
                void refreshTeamActivity(pageRoot, panel);
                startPolling(pageRoot, activeController);
            }

            rowPointerOrigin = null;

            return;
        }

        const rowToggle = event.target.closest?.('[data-team-activity-row-toggle]');

        if (!rowToggle || !panel.contains(rowToggle)) {
            rowPointerOrigin = null;

            return;
        }

        if (isInteractiveRowTarget(event.target) || wasTextDragSelect(rowPointerOrigin, event, rowToggle)) {
            rowPointerOrigin = null;

            return;
        }

        rowPointerOrigin = null;

        const row = rowToggle.closest('[data-team-activity-agent]');

        if (!row) {
            return;
        }

        event.preventDefault();
        toggleRowExpanded(panel, row, pageRoot);
    });

    panel.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }

        const rowToggle = event.target.closest?.('[data-team-activity-row-toggle]');

        if (!rowToggle || event.target !== rowToggle || !panel.contains(rowToggle)) {
            return;
        }

        const row = rowToggle.closest('[data-team-activity-agent]');

        if (!row) {
            return;
        }

        event.preventDefault();
        toggleRowExpanded(panel, row, pageRoot);
    });
};

export const initDashboardTeamActivity = (pageRoot) => {
    const panel = pageRoot?.querySelector?.('[data-team-activity-panel]');

    if (!panel?.dataset.teamActivityRefreshUrl) {
        return null;
    }

    pageRoot[PANEL_CONTROLLER]?.destroy?.();

    const controller = new AbortController();
    activeController = controller;
    bindUserActivityListeners();
    recordUserActivity();

    const collapsed = readCollapsed();
    setPanelCollapsed(panel, collapsed);
    restoreExpandedRows(panel);
    bindPanelInteractions(pageRoot, panel);

    const visibilityHandler = () => {
        if (!document.hidden) {
            recordUserActivity();
        }
    };

    document.addEventListener('visibilitychange', visibilityHandler, { signal: controller.signal });

    const collapseOnOutsideInteraction = (event) => {
        const activePanel = pageRoot.querySelector('[data-team-activity-panel]');

        if (!activePanel || isPanelCollapsed(activePanel)) {
            return;
        }

        if (event.target.closest('[data-team-activity-panel]')) {
            return;
        }

        setPanelCollapsed(activePanel, true);
        clearPollTimeout();
    };

    pageRoot.addEventListener('click', collapseOnOutsideInteraction, { signal: controller.signal });
    pageRoot.addEventListener('input', (event) => {
        const activePanel = pageRoot.querySelector('[data-team-activity-panel]');

        if (!activePanel || isPanelCollapsed(activePanel)) {
            return;
        }

        if (event.target.closest('input[type="search"]')) {
            setPanelCollapsed(activePanel, true);
            clearPollTimeout();
        }
    }, { signal: controller.signal });

    const refreshController = {
        destroy: () => {
            controller.abort();
            clearPollTimeout();

            if (activeController === controller) {
                activeController = null;
            }

            if (pageRoot[PANEL_CONTROLLER] === refreshController) {
                delete pageRoot[PANEL_CONTROLLER];
            }
        },
    };

    pageRoot[PANEL_CONTROLLER] = refreshController;

    if (!collapsed) {
        if (collectExpandedFromDom(panel).length > 0) {
            void refreshTeamActivity(pageRoot, panel);
        }

        startPolling(pageRoot, controller);
    }

    return refreshController;
};

export const resetDashboardTeamActivityStateForTests = () => {
    refreshInFlight = false;
    lastUserActivityAt = Date.now();
    activeController = null;
    clearPollTimeout();
};
