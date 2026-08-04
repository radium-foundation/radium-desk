const EXPAND_STORAGE_KEY = 'radium.platform.expandedZones';
const DEFAULT_ZONE_CONCURRENCY = 3;

/** Priority zones eligible for automatic post-paint / poll refresh when stale or unavailable. */
const PRIORITY_AUTO_REFRESH_ZONES = new Set([
    'critical_alerts',
    'executive_snapshot',
    'platform_health',
    'integration_health',
]);

const zoneIsFresh = (zone) => (
    zone.dataset.zoneAvailable !== 'false' && zone.dataset.zoneStale !== 'true'
);

/**
 * Intelligent auto-refresh:
 * - Fresh snapshots: never
 * - Stale/unavailable Priority 1–2: yes
 * - Lower priority: only when expanded
 * - Explicit refresh-all / per-zone button: caller bypasses this
 */
const shouldAutoRefreshZone = (zone) => {
    if (zoneIsFresh(zone)) {
        return false;
    }

    const key = zone.dataset.platformZone || '';

    if (PRIORITY_AUTO_REFRESH_ZONES.has(key)) {
        return true;
    }

    return zone.dataset.expanded === 'true';
};

const applyCardRefreshPayload = (card, payload) => {
    const slot = card.closest('[data-platform-card-slot]') || card.parentElement;

    if (!slot || typeof payload.html !== 'string') {
        throw new Error('Invalid refresh payload');
    }

    slot.innerHTML = payload.html;

    const nextCard = slot.querySelector('[data-platform-card]');

    if (nextCard) {
        bindRefreshButton(nextCard);
    }

    return nextCard ?? card;
};

const refreshPlatformCard = async (card, { surfaceErrors = true } = {}) => {
    const url = card.dataset.refreshUrl;

    if (!url || document.hidden) {
        return false;
    }

    const button = card.querySelector('[data-platform-card-refresh]');
    const icon = button?.querySelector('i');

    if (button instanceof HTMLButtonElement) {
        button.disabled = true;
        button.classList.add('disabled');
    }

    if (icon) {
        icon.classList.add('spin');
    }

    try {
        const response = await fetch(url, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error(`Refresh failed (${response.status})`);
        }

        const payload = await response.json();
        applyCardRefreshPayload(card, payload);

        return true;
    } catch (error) {
        console.error(error);

        if (surfaceErrors) {
            window.alert('Unable to refresh this card. Please try again.');
        }

        return false;
    } finally {
        if (button instanceof HTMLButtonElement) {
            button.disabled = false;
            button.classList.remove('disabled');
        }

        if (icon) {
            icon.classList.remove('spin');
        }
    }
};

const bindRefreshButton = (card) => {
    const button = card.querySelector('[data-platform-card-refresh]');

    if (!button || button.dataset.bound === 'true') {
        return;
    }

    button.dataset.bound = 'true';
    button.addEventListener('click', async () => {
        await refreshPlatformCard(card, { surfaceErrors: true });
    });
};

const bindCardRefreshButtons = (root) => {
    root.querySelectorAll('[data-platform-card]').forEach((card) => {
        bindRefreshButton(card);
    });
};

const readExpandedZones = () => {
    try {
        const raw = window.localStorage.getItem(EXPAND_STORAGE_KEY);
        const parsed = raw ? JSON.parse(raw) : {};

        return parsed && typeof parsed === 'object' ? parsed : {};
    } catch (error) {
        return {};
    }
};

const writeExpandedZones = (map) => {
    try {
        window.localStorage.setItem(EXPAND_STORAGE_KEY, JSON.stringify(map));
    } catch (error) {
        // Ignore quota / private mode failures.
    }
};

const setZoneExpanded = (zoneKey, expanded) => {
    const map = readExpandedZones();

    if (expanded) {
        map[zoneKey] = true;
    } else {
        delete map[zoneKey];
    }

    writeExpandedZones(map);
};

const integrationExpandStorageKey = (zoneKey, itemKey) => `${zoneKey}:${itemKey}`;

const applyIntegrationExpandPayload = (button, panel, payload) => {
    if (!panel || typeof payload.html !== 'string') {
        throw new Error('Invalid integration expand payload');
    }

    panel.innerHTML = payload.html;
    panel.hidden = false;
    panel.classList.remove('d-none');
    button.setAttribute('aria-expanded', 'true');
    button.textContent = 'Collapse';
    button.dataset.expanded = 'true';

    panel.querySelectorAll('[data-platform-integration-expand]').forEach((nested) => {
        bindIntegrationExpandButton(nested);
    });
};

const collapseIntegrationExpand = (button, panel) => {
    if (panel) {
        panel.hidden = true;
        panel.classList.add('d-none');
        panel.innerHTML = '';
    }

    button.setAttribute('aria-expanded', 'false');
    button.textContent = 'Expand';
    button.dataset.expanded = 'false';
};

const resolveExpandPanel = (button) => {
    const target = button.dataset.expandTarget;

    if (target) {
        return document.querySelector(target);
    }

    return button.closest('[data-platform-integration-card]')
        ?.querySelector('[data-platform-integration-expand-panel]');
};

const expandPlatformIntegration = async (button, { surfaceErrors = true, persist = true } = {}) => {
    const url = button.dataset.expandUrl;
    const panel = resolveExpandPanel(button);

    if (!url || !panel || document.hidden) {
        return false;
    }

    button.disabled = true;

    try {
        const response = await fetch(url, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error(`Integration expand failed (${response.status})`);
        }

        const payload = await response.json();
        applyIntegrationExpandPayload(button, panel, payload);

        if (persist) {
            const zoneKey = button.dataset.zoneKey || 'integration_health';
            const itemKey = button.dataset.integrationKey || payload.item || 'default';
            setZoneExpanded(integrationExpandStorageKey(zoneKey, itemKey), true);
        }

        return true;
    } catch (error) {
        console.error(error);

        if (surfaceErrors) {
            window.alert('Unable to load diagnostics. Please try again.');
        }

        return false;
    } finally {
        button.disabled = false;
    }
};

const togglePlatformIntegrationExpand = async (button) => {
    const panel = resolveExpandPanel(button);
    const zoneKey = button.dataset.zoneKey || 'integration_health';
    const itemKey = button.dataset.integrationKey || 'default';

    if (button.dataset.expanded === 'true') {
        collapseIntegrationExpand(button, panel);
        setZoneExpanded(integrationExpandStorageKey(zoneKey, itemKey), false);

        return false;
    }

    return expandPlatformIntegration(button, { surfaceErrors: true, persist: true });
};

const bindIntegrationExpandButton = (button) => {
    if (!button || button.dataset.bound === 'true') {
        return;
    }

    button.dataset.bound = 'true';
    button.addEventListener('click', async () => {
        await togglePlatformIntegrationExpand(button);
    });
};

const bindIntegrationExpands = (root) => {
    root.querySelectorAll('[data-platform-integration-expand]').forEach((button) => {
        bindIntegrationExpandButton(button);
    });
};

const applyZoneExpandPayload = (zone, payload) => {
    const panel = zone.querySelector('[data-platform-zone-expand-panel]');

    if (!panel || typeof payload.html !== 'string') {
        throw new Error('Invalid expand payload');
    }

    panel.innerHTML = payload.html;
    panel.hidden = false;
    panel.classList.remove('d-none');
    bindIntegrationExpands(panel);

    const button = zone.querySelector('[data-platform-zone-expand]');

    if (button) {
        button.setAttribute('aria-expanded', 'true');
        button.textContent = 'Collapse';
    }

    zone.dataset.expanded = 'true';
};

const collapseZoneExpand = (zone) => {
    const panel = zone.querySelector('[data-platform-zone-expand-panel]');

    if (panel) {
        panel.hidden = true;
        panel.classList.add('d-none');
        panel.innerHTML = '';
    }

    const button = zone.querySelector('[data-platform-zone-expand]');

    if (button) {
        button.setAttribute('aria-expanded', 'false');
        button.textContent = 'Expand';
    }

    zone.dataset.expanded = 'false';
};

const expandPlatformZone = async (zone, { surfaceErrors = true, persist = true } = {}) => {
    const url = zone.dataset.expandUrl;

    if (!url || document.hidden) {
        return false;
    }

    // Expanding a stale/unavailable zone: refresh summary first.
    if (! zoneIsFresh(zone)) {
        await refreshPlatformZone(zone, { surfaceErrors: false });
    }

    try {
        const response = await fetch(url, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error(`Expand failed (${response.status})`);
        }

        const payload = await response.json();
        applyZoneExpandPayload(zone, payload);

        if (persist) {
            setZoneExpanded(zone.dataset.platformZone, true);
        }

        return true;
    } catch (error) {
        console.error(error);

        if (surfaceErrors) {
            window.alert('Unable to expand this zone. Please try again.');
        }

        return false;
    }
};

const togglePlatformZoneExpand = async (zone) => {
    if (zone.dataset.expanded === 'true') {
        collapseZoneExpand(zone);
        setZoneExpanded(zone.dataset.platformZone, false);

        return false;
    }

    return expandPlatformZone(zone, { surfaceErrors: true, persist: true });
};

const applyZoneRefreshPayload = (zone, payload) => {
    const body = zone.querySelector('[data-platform-zone-body]');

    if (!body || typeof payload.html !== 'string') {
        throw new Error('Invalid zone refresh payload');
    }

    body.innerHTML = payload.html;
    bindCardRefreshButtons(body);
    bindIntegrationExpands(body);
    bindZoneControls(zone);

    if (payload.status) {
        zone.dataset.zoneStatus = payload.status;
    }

    if (typeof payload.available === 'boolean') {
        zone.dataset.zoneAvailable = payload.available ? 'true' : 'false';
    }

    if (typeof payload.stale === 'boolean') {
        zone.dataset.zoneStale = payload.stale ? 'true' : 'false';
    }

    const statusBadge = zone.querySelector('[data-platform-zone-status]');

    if (statusBadge && payload.status_label) {
        statusBadge.textContent = payload.status_label;
    }

    let staleBadge = zone.querySelector('[data-platform-zone-stale]');

    if (payload.stale === true) {
        if (! staleBadge) {
            staleBadge = document.createElement('span');
            staleBadge.className = 'badge text-bg-warning';
            staleBadge.dataset.platformZoneStale = '';
            staleBadge.title = 'Last known snapshot — background refresh pending';
            staleBadge.textContent = 'Stale';
            statusBadge?.parentElement?.insertBefore(staleBadge, statusBadge.nextSibling);
        }
    } else if (staleBadge) {
        staleBadge.remove();
    }

    const updatedAt = zone.querySelector('[data-platform-zone-updated-at]');

    if (updatedAt && payload.updated_at) {
        try {
            updatedAt.textContent = new Date(payload.updated_at).toLocaleTimeString([], {
                hour: 'numeric',
                minute: '2-digit',
            });
        } catch (error) {
            updatedAt.textContent = payload.updated_at;
        }
    }

    if (zone.dataset.expanded === 'true' && zone.dataset.expandable === 'true') {
        expandPlatformZone(zone, { surfaceErrors: false, persist: false });
    }
};

const refreshPlatformZone = async (zone, { surfaceErrors = true } = {}) => {
    const url = zone.dataset.refreshUrl;

    if (!url || document.hidden) {
        return false;
    }

    const button = zone.querySelector('[data-platform-zone-refresh]');
    const icon = button?.querySelector('i');

    if (button instanceof HTMLButtonElement) {
        button.disabled = true;
        button.classList.add('disabled');
    }

    if (icon) {
        icon.classList.add('spin');
    }

    try {
        const response = await fetch(url, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error(`Zone refresh failed (${response.status})`);
        }

        const payload = await response.json();
        applyZoneRefreshPayload(zone, payload);

        return true;
    } catch (error) {
        console.error(error);

        if (surfaceErrors) {
            window.alert('Unable to refresh this zone. Please try again.');
        }

        return false;
    } finally {
        if (button instanceof HTMLButtonElement) {
            button.disabled = false;
            button.classList.remove('disabled');
        }

        if (icon) {
            icon.classList.remove('spin');
        }
    }
};

const bindZoneControls = (zone) => {
    zone.querySelectorAll('[data-platform-zone-refresh]').forEach((refreshButton) => {
        if (refreshButton.dataset.bound === 'true') {
            return;
        }

        refreshButton.dataset.bound = 'true';
        refreshButton.addEventListener('click', async () => {
            await refreshPlatformZone(zone, { surfaceErrors: true });
        });
    });

    const expandButton = zone.querySelector('[data-platform-zone-expand]');

    if (expandButton && expandButton.dataset.bound !== 'true') {
        expandButton.dataset.bound = 'true';
        expandButton.addEventListener('click', async () => {
            await togglePlatformZoneExpand(zone);
        });
    }

    bindIntegrationExpands(zone);
};

const refreshableZones = (root) => (
    [...root.querySelectorAll('[data-platform-zone][data-refresh-url]')]
        .sort((a, b) => Number(a.dataset.platformZonePriority || 99) - Number(b.dataset.platformZonePriority || 99))
);

const zonesNeedingAutoRefresh = (root) => refreshableZones(root).filter((zone) => shouldAutoRefreshZone(zone));

const runWithConcurrency = async (items, concurrency, worker) => {
    const queue = [...items];
    const limit = Math.max(1, concurrency);

    const runners = Array.from({ length: Math.min(limit, queue.length) }, async () => {
        while (queue.length > 0) {
            if (document.hidden) {
                return;
            }

            const item = queue.shift();

            if (!item) {
                return;
            }

            await worker(item);
        }
    });

    await Promise.all(runners);
};

/**
 * @param {{ surfaceErrors?: boolean, forceAll?: boolean }} options
 * forceAll: user-triggered Refresh All — refreshes every zone.
 *
 * Contributor zones (platform_health, executive_snapshot, integration_health)
 * always refresh before critical_alerts so aggregation never bakes a cold Pending
 * snapshot that races the shared health probe.
 */
const refreshZonesByPriority = async (root, { surfaceErrors = false, forceAll = false } = {}) => {
    if (document.hidden) {
        return;
    }

    const concurrency = Number(root.dataset.zoneConcurrency || DEFAULT_ZONE_CONCURRENCY);
    const zones = forceAll ? refreshableZones(root) : zonesNeedingAutoRefresh(root);

    if (zones.length === 0) {
        return;
    }

    const ordered = [...zones].sort((left, right) => {
        const leftKey = left.dataset.platformZone || '';
        const rightKey = right.dataset.platformZone || '';
        const leftRank = leftKey === 'critical_alerts' ? 1 : 0;
        const rightRank = rightKey === 'critical_alerts' ? 1 : 0;

        return leftRank - rightRank;
    });

    const contributors = ordered.filter((zone) => zone.dataset.platformZone !== 'critical_alerts');
    const criticalAlerts = ordered.filter((zone) => zone.dataset.platformZone === 'critical_alerts');

    if (contributors.length > 0) {
        await runWithConcurrency(
            contributors,
            concurrency,
            (zone) => refreshPlatformZone(zone, { surfaceErrors }),
        );
    }

    if (criticalAlerts.length > 0) {
        await runWithConcurrency(
            criticalAlerts,
            1,
            (zone) => refreshPlatformZone(zone, { surfaceErrors }),
        );
    }
};

const restoreExpandedZones = async (root) => {
    const expanded = readExpandedZones();

    for (const zone of root.querySelectorAll('[data-platform-zone][data-expandable="true"]')) {
        const key = zone.dataset.platformZone;

        if (!key || !expanded[key]) {
            continue;
        }

        await expandPlatformZone(zone, { surfaceErrors: false, persist: false });
    }

    for (const button of root.querySelectorAll('[data-platform-integration-expand]')) {
        const zoneKey = button.dataset.zoneKey || 'integration_health';
        const itemKey = button.dataset.integrationKey;

        if (!itemKey || !expanded[integrationExpandStorageKey(zoneKey, itemKey)]) {
            continue;
        }

        await expandPlatformIntegration(button, { surfaceErrors: false, persist: false });
    }
};

let pollIntervalId = null;
let pollPageRoot = null;
let pollIntervalMs = 0;
let pollVisibilityHandler = null;

const refreshableCards = (root) => (
    [...root.querySelectorAll('[data-platform-card][data-refresh-url]')]
);

const refreshAllPlatformCards = async (root, { surfaceErrors = false } = {}) => {
    if (document.hidden) {
        return;
    }

    await Promise.all(
        refreshableCards(root).map((card) => refreshPlatformCard(card, { surfaceErrors })),
    );
};

const pollPlatformDashboard = async (root) => {
    if (document.hidden || !root) {
        return;
    }

    // Intelligent only — never force every zone on poll / visibility return.
    if (root.querySelector('[data-platform-zone]')) {
        await refreshZonesByPriority(root, { surfaceErrors: false, forceAll: false });
    }
};

const refreshAllPlatform = async (root, { surfaceErrors = false } = {}) => {
    if (root.querySelector('[data-platform-zone]')) {
        await refreshZonesByPriority(root, { surfaceErrors, forceAll: true });
    }

    await refreshAllPlatformCards(root, { surfaceErrors });
};

export const stopPlatformPolling = () => {
    if (pollIntervalId === null) {
        return;
    }

    window.clearInterval(pollIntervalId);
    pollIntervalId = null;
};

const bindPlatformPollingVisibilityListener = () => {
    if (pollVisibilityHandler !== null) {
        return;
    }

    pollVisibilityHandler = () => {
        if (document.visibilityState === 'hidden') {
            stopPlatformPolling();

            return;
        }

        if (pollPageRoot === null) {
            return;
        }

        pollPlatformDashboard(pollPageRoot);
        startPlatformPolling(pollPageRoot, pollIntervalMs);
    };

    document.addEventListener('visibilitychange', pollVisibilityHandler);
};

export const startPlatformPolling = (root, intervalMs) => {
    pollPageRoot = root;
    pollIntervalMs = intervalMs;

    bindPlatformPollingVisibilityListener();

    if (intervalMs <= 0) {
        stopPlatformPolling();

        return;
    }

    if (document.visibilityState === 'hidden') {
        stopPlatformPolling();

        return;
    }

    if (pollIntervalId !== null) {
        return;
    }

    pollIntervalId = window.setInterval(() => {
        pollPlatformDashboard(root);
    }, intervalMs);
};

export const initPlatformDashboard = () => {
    const root = document.getElementById('platform-dashboard-root');

    if (!root) {
        return;
    }

    bindCardRefreshButtons(root);

    root.querySelectorAll('[data-platform-zone]').forEach((zone) => {
        bindZoneControls(zone);
    });

    // Priority async refresh after first paint (snapshot-only HTML).
    if (root.hasAttribute('data-platform-zones')) {
        window.requestAnimationFrame(() => {
            refreshZonesByPriority(root, { surfaceErrors: false }).then(() => {
                restoreExpandedZones(root);
            });
        });
    } else {
        restoreExpandedZones(root);
    }

    const intervalMs = Number(root.dataset.pollIntervalSeconds ?? 0) * 1000;

    if (intervalMs > 0) {
        startPlatformPolling(root, intervalMs);
    }

    window.RadiumDesk = window.RadiumDesk || {};
    window.RadiumDesk.platformDashboard = {
        refreshAll: (pageRoot = root) => refreshAllPlatform(pageRoot, { surfaceErrors: true }),
        refreshCard: (card) => refreshPlatformCard(card, { surfaceErrors: true }),
        refreshZone: (zone) => refreshPlatformZone(zone, { surfaceErrors: true }),
        refreshZones: (pageRoot = root) => refreshZonesByPriority(pageRoot, { surfaceErrors: true, forceAll: true }),
        expandZone: (zone) => expandPlatformZone(zone, { surfaceErrors: true }),
        expandIntegration: (button) => expandPlatformIntegration(button, { surfaceErrors: true }),
        shouldAutoRefreshZone,
    };
};

export {
    refreshPlatformZone,
    refreshZonesByPriority,
    expandPlatformZone,
    expandPlatformIntegration,
    restoreExpandedZones,
    runWithConcurrency,
    shouldAutoRefreshZone,
    zoneIsFresh,
};
