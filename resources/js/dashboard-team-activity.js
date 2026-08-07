import { initTooltips } from './tooltips';

const PANEL_CONTROLLER = Symbol('dashboardTeamActivityController');
const PANEL_COLLAPSED_KEY = 'radium.teamActivityPanel.collapsed';
const EXPANDED_AGENTS_KEY = 'radium.teamActivityPanel.expandedAgents';
const TOOLTIP_RUNTIME_ATTRS = ['aria-describedby', 'data-bs-original-title'];

const HYDRATE_LOADING_HTML = `
    <p class="team-activity-empty text-muted small mb-0" data-team-activity-hydrate-loading>
        Loading team activity…
    </p>
`.trim();

const HYDRATE_ERROR_HTML = `
    <div class="team-activity-hydrate-error" data-team-activity-hydrate-error role="alert">
        <p class="team-activity-hydrate-error__message text-muted small mb-0">
            Unable to load team activity. Retry.
        </p>
        <button type="button"
                class="team-activity-hydrate-error__retry btn btn-sm btn-outline-secondary"
                data-team-activity-retry>
            Retry
        </button>
    </div>
`.trim();

const EMPTY_ROSTER_HTML = `
    <p class="team-activity-empty text-muted small mb-0" data-team-activity-empty-roster>
        No team members to show.
    </p>
`.trim();

const eventElement = (event) => {
    const target = event?.target;

    if (target instanceof Element) {
        return target;
    }

    return target?.parentElement instanceof Element ? target.parentElement : null;
};

const isDevEnvironment = () => Boolean(import.meta.env?.DEV);

const warnTeamActivity = (message, detail = undefined) => {
    if (!isDevEnvironment()) {
        return;
    }

    if (detail === undefined) {
        console.warn(`[team-activity] ${message}`);

        return;
    }

    console.warn(`[team-activity] ${message}`, detail);
};

const showPanelBodyMessage = (panel, html) => {
    const body = panel.querySelector('[data-team-activity-panel-body]');

    if (!body) {
        return;
    }

    body.innerHTML = html;
};

const hasHydratedRoster = (panel) => Boolean(
    panel.querySelector('[data-team-activity-agent], [data-team-activity-list], [data-team-activity-empty-roster]'),
);

const shouldShowHydrateLoading = (panel, { force = false } = {}) => {
    if (panel.querySelector('[data-team-activity-hydrate-error]')) {
        return true;
    }

    if (panel.dataset.teamActivityLazy === '1') {
        return true;
    }

    if (force && !hasHydratedRoster(panel)) {
        return true;
    }

    return Boolean(panel.querySelector('[data-team-activity-hydrate-loading]'));
};

const markPanelHydrated = (panel) => {
    delete panel.dataset.teamActivityLazy;
    panel.dataset.teamActivityHydrated = '1';
};

const stablePanelHtml = (htmlOrElement) => {
    const template = document.createElement('template');

    if (typeof htmlOrElement === 'string') {
        template.innerHTML = htmlOrElement.trim();
    } else if (htmlOrElement?.outerHTML) {
        template.innerHTML = htmlOrElement.outerHTML;
    } else {
        return '';
    }

    const root = template.content.firstElementChild;

    if (!root) {
        return '';
    }

    [root, ...root.querySelectorAll('*')].forEach((node) => {
        TOOLTIP_RUNTIME_ATTRS.forEach((attr) => {
            node.removeAttribute(attr);
        });
    });

    // Server HTML always ships collapsed attrs; ignore them for equality.
    root.classList.remove('is-collapsed');
    root.removeAttribute('data-team-activity-collapsed');
    root.removeAttribute('data-team-activity-bound');
    root.removeAttribute('data-team-activity-lazy');
    root.removeAttribute('data-team-activity-hydrated');

    return root.outerHTML.replace(/\s+/g, ' ').trim();
};

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
    const intervalMs = Number(panel?.dataset.teamActivityPollIntervalMs ?? 60000);

    return intervalMs > 0 ? intervalMs : 60000;
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
        return null;
    }

    const wasCollapsed = isPanelCollapsed(panel);

    panel.replaceWith(nextPanel);
    setPanelCollapsed(nextPanel, wasCollapsed);
    markPanelHydrated(nextPanel);
    restoreExpandedRows(nextPanel);
    bindPanelInteractions(pageRoot, nextPanel);
    initTooltips(nextPanel);

    return nextPanel;
};

const showHydrateError = (panel, reason, detail = undefined) => {
    showPanelBodyMessage(panel, HYDRATE_ERROR_HTML);
    warnTeamActivity(reason, detail);
};

const applyGenuineEmptyRoster = (panel) => {
    showPanelBodyMessage(panel, EMPTY_ROSTER_HTML);
    markPanelHydrated(panel);
};

const refreshTeamActivity = async (pageRoot, panel, { force = false } = {}) => {
    const refreshUrl = panel.dataset.teamActivityRefreshUrl;

    if (!refreshUrl || document.hidden || refreshInFlight || isPanelCollapsed(panel) || !isUserActive(panel)) {
        return;
    }

    const hadRoster = hasHydratedRoster(panel);

    if (shouldShowHydrateLoading(panel, { force })) {
        showPanelBodyMessage(panel, HYDRATE_LOADING_HTML);
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

        const currentPanel = pageRoot.querySelector('[data-team-activity-panel]');

        if (!currentPanel || isPanelCollapsed(currentPanel)) {
            return;
        }

        if (!response.ok) {
            // Keep an already-hydrated roster visible during poll failures.
            if (hadRoster && !currentPanel.querySelector('[data-team-activity-hydrate-loading], [data-team-activity-hydrate-error]')) {
                warnTeamActivity(`poll failed with HTTP ${response.status}`);

                return;
            }

            showHydrateError(currentPanel, `hydrate failed with HTTP ${response.status}`);

            return;
        }

        let data;

        try {
            data = await response.json();
        } catch (error) {
            if (hadRoster && !currentPanel.querySelector('[data-team-activity-hydrate-loading], [data-team-activity-hydrate-error]')) {
                warnTeamActivity('poll returned invalid JSON', error);

                return;
            }

            showHydrateError(currentPanel, 'hydrate returned invalid JSON', error);

            return;
        }

        // Genuine empty roster only — never remove the shell/widget.
        if (data.empty === true) {
            applyGenuineEmptyRoster(currentPanel);

            return;
        }

        if (!data.html) {
            if (hadRoster && !currentPanel.querySelector('[data-team-activity-hydrate-loading], [data-team-activity-hydrate-error]')) {
                warnTeamActivity('poll returned success without html');

                return;
            }

            showHydrateError(currentPanel, 'hydrate returned success without html');

            return;
        }

        if (!force && stablePanelHtml(data.html) === stablePanelHtml(currentPanel)) {
            markPanelHydrated(currentPanel);

            return;
        }

        applyPanelHtml(pageRoot, currentPanel, data.html);
    } catch (error) {
        const currentPanel = pageRoot.querySelector('[data-team-activity-panel]');

        if (!currentPanel || isPanelCollapsed(currentPanel)) {
            return;
        }

        if (hadRoster && !currentPanel.querySelector('[data-team-activity-hydrate-loading], [data-team-activity-hydrate-error]')) {
            warnTeamActivity('poll network error', error);

            return;
        }

        showHydrateError(currentPanel, 'hydrate network error', error);
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

    panel.addEventListener('click', (event) => {
        // Always use the listener's panel (currentTarget). Closed-over nodes go stale after replaceWith.
        const activePanel = event.currentTarget instanceof Element
            ? event.currentTarget
            : pageRoot.querySelector('[data-team-activity-panel]');

        if (!(activePanel instanceof Element)) {
            return;
        }

        const target = eventElement(event);
        const retryButton = target?.closest('[data-team-activity-retry]');

        if (retryButton && activePanel.contains(retryButton)) {
            event.preventDefault();
            event.stopPropagation();
            recordUserActivity();

            if (activeController && !activeController.signal.aborted) {
                void refreshTeamActivity(pageRoot, activePanel, { force: true });
                startPolling(pageRoot, activeController);
            }

            return;
        }

        const panelToggle = target?.closest('[data-team-activity-panel-toggle]');

        if (panelToggle && activePanel.contains(panelToggle)) {
            event.preventDefault();
            event.stopPropagation();

            const nextCollapsed = !activePanel.classList.contains('is-collapsed');
            setPanelCollapsed(activePanel, nextCollapsed);

            if (nextCollapsed) {
                clearPollTimeout();
            } else if (activeController && !activeController.signal.aborted) {
                recordUserActivity();
                void refreshTeamActivity(pageRoot, activePanel, { force: true });
                startPolling(pageRoot, activeController);
            }

            return;
        }

        const rowToggle = target?.closest('[data-team-activity-row-toggle]');

        if (!rowToggle || !activePanel.contains(rowToggle)) {
            return;
        }

        const row = rowToggle.closest('[data-team-activity-agent]');

        if (!row) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        toggleRowExpanded(activePanel, row, pageRoot);
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
        const activePanel = pageRoot.querySelector('[data-team-activity-panel]');

        if (document.hidden) {
            clearPollTimeout();

            return;
        }

        recordUserActivity();

        if (!activePanel || isPanelCollapsed(activePanel) || controller.signal.aborted) {
            return;
        }

        void refreshTeamActivity(pageRoot, activePanel);
        startPolling(pageRoot, controller);
    };

    document.addEventListener('visibilitychange', visibilityHandler, { signal: controller.signal });

    const collapseOnOutsideInteraction = (event) => {
        const activePanel = pageRoot.querySelector('[data-team-activity-panel]');

        if (!activePanel || isPanelCollapsed(activePanel)) {
            return;
        }

        const target = eventElement(event);

        if (target?.closest('[data-team-activity-panel]')) {
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

        const target = eventElement(event);

        if (target?.closest('input[type="search"]')) {
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
        // SSR ships a shell only; always hydrate when restored expanded.
        void refreshTeamActivity(pageRoot, panel, { force: true });
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
