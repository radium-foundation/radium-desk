import { refreshDashboard, startPolling, stopPolling } from './live-dashboard';
import { setDashboardSearchActive, isDashboardSearchActive } from './dashboard-search-mode';
import { setServiceCasePagination, setServiceCaseSearchQuery } from './dashboard-service-case-state';
import { syncReadyQueueMembershipScope } from './ready-queue-membership-memory';

const FILTER_WORKSPACES = new Set([
    'overdue',
    'warning',
    'my_attention',
    'needs_attention',
    'high_priority',
    'pending_support',
]);

const EMBEDDED_WORKSPACES = new Set(['active_cases', 'refunds']);

const SCHEDULED_QUEUE = 'scheduled';
const READY_QUEUE = 'action_required';

export const isEmbeddedWorkspace = (workspace) => EMBEDDED_WORKSPACES.has(workspace);

/**
 * Map legacy listing URLs (pre–Phase 2 KPI hrefs) to embedded workspace targets.
 * Used when Blade still emits /incidents or /refunds but Phase 2 embed is enabled.
 */
export const parseLegacyEmbeddedNavigationTarget = (href) => {
    let url;

    try {
        url = new URL(href, window.location.origin);
    } catch (error) {
        return null;
    }

    const path = url.pathname.replace(/\/+$/, '') || '/';
    const status = url.searchParams.get('status');

    if (path.endsWith('/incidents') && status === 'active') {
        return {
            workspace: 'active_cases',
            kind: 'embedded',
            operationQueue: READY_QUEUE,
            serviceCaseFilter: READY_QUEUE,
            url,
            query: {
                workspace: 'active_cases',
                status: 'active',
                ...Object.fromEntries(url.searchParams.entries()),
            },
        };
    }

    if (path.endsWith('/refunds')) {
        const query = Object.fromEntries(url.searchParams.entries());

        if (!query.status && !query.queue) {
            query.status = 'pending';
        }

        return {
            workspace: 'refunds',
            kind: 'embedded',
            operationQueue: READY_QUEUE,
            serviceCaseFilter: READY_QUEUE,
            url,
            query: {
                workspace: 'refunds',
                ...query,
            },
        };
    }

    return null;
};

export const parseDashboardNavigationTarget = (href, currentPath = '/dashboard') => {
    let url;

    try {
        url = new URL(href, window.location.origin);
    } catch (error) {
        return null;
    }

    const isDashboardPath = url.pathname === currentPath || url.pathname.endsWith('/dashboard');

    if (!isDashboardPath) {
        return null;
    }

    const workspace = url.searchParams.get('workspace');
    const queue = url.searchParams.get('queue');
    const filter = url.searchParams.get('filter');
    const view = url.searchParams.get('view');

    if (workspace && isEmbeddedWorkspace(workspace)) {
        return {
            workspace,
            kind: 'embedded',
            operationQueue: READY_QUEUE,
            serviceCaseFilter: READY_QUEUE,
            url,
            query: Object.fromEntries(url.searchParams.entries()),
        };
    }

    if (!workspace && !queue && !filter && !view) {
        return {
            workspace: READY_QUEUE,
            kind: 'case_queue',
            operationQueue: READY_QUEUE,
            serviceCaseFilter: READY_QUEUE,
            url,
        };
    }

    if (workspace && FILTER_WORKSPACES.has(workspace)) {
        return {
            workspace,
            kind: 'case_queue',
            operationQueue: queue || (workspace === 'my_attention' ? 'my_work' : READY_QUEUE),
            serviceCaseFilter: workspace,
            url,
        };
    }

    if (workspace) {
        return {
            workspace,
            kind: 'case_queue',
            operationQueue: workspace,
            serviceCaseFilter: filter || workspace,
            url,
        };
    }

    if (filter && FILTER_WORKSPACES.has(filter)) {
        return {
            workspace: filter,
            kind: 'case_queue',
            operationQueue: queue || (filter === 'my_attention' ? 'my_work' : READY_QUEUE),
            serviceCaseFilter: filter,
            url,
        };
    }

    if (queue) {
        return {
            workspace: queue,
            kind: 'case_queue',
            operationQueue: queue,
            serviceCaseFilter: filter || queue,
            url,
        };
    }

    if (view) {
        return {
            workspace: view,
            kind: 'case_queue',
            operationQueue: view,
            serviceCaseFilter: filter || view,
            url,
        };
    }

    return null;
};

export const buildWorkspaceHistoryUrl = (workspace, { pathname = '/dashboard', search = '', extraParams = {} } = {}) => {
    const url = new URL(pathname, window.location.origin);
    const current = new URLSearchParams(search);

    ['workspace', 'queue', 'filter', 'view'].forEach((key) => current.delete(key));

    if (workspace) {
        url.searchParams.set('workspace', workspace);
    }

    Object.entries(extraParams).forEach(([key, value]) => {
        if (value === undefined || value === null || value === '' || key === 'workspace') {
            return;
        }

        url.searchParams.set(key, String(value));
    });

    current.forEach((value, key) => {
        if (key === 'q' || key === 'open_customer_360' || key === 'open_more_menu') {
            return;
        }

        if (!url.searchParams.has(key)) {
            url.searchParams.set(key, value);
        }
    });

    return `${url.pathname}${url.search}`;
};

const setSkeletonVisible = (card, visible) => {
    if (!card) {
        return;
    }

    card.classList.toggle('is-workspace-switching', visible);
    const skeleton = card.querySelector('[data-operations-workspace-skeleton]');
    skeleton?.classList.toggle('d-none', !visible);
};

const updateQueueChips = (card, operationQueue) => {
    card?.querySelectorAll('[data-operations-workspace-link][data-workspace]').forEach((chip) => {
        if (!chip.classList.contains('dashboard-case-filter-chip')) {
            return;
        }

        const isActive = chip.dataset.workspace === operationQueue;
        chip.classList.toggle('is-active', isActive);
        chip.setAttribute('aria-selected', isActive ? 'true' : 'false');

        if (isActive) {
            chip.setAttribute('aria-current', 'page');
            chip.classList.remove('d-none');
        } else {
            chip.removeAttribute('aria-current');
        }
    });
};

const updateScheduledChrome = (card, operationQueue) => {
    if (!card) {
        return;
    }

    const isScheduled = operationQueue === SCHEDULED_QUEUE;

    if (isScheduled) {
        card.setAttribute('data-scheduled-appointment-board', 'true');
        card.dataset.operationsWidget = 'appointments';
    } else {
        card.removeAttribute('data-scheduled-appointment-board');
        card.dataset.operationsWidget = operationQueue === READY_QUEUE ? 'ready-queue' : 'service-cases';
    }

    card.querySelectorAll('th.status-sla-cell, th.appointment-status-cell').forEach((th) => {
        th.classList.toggle('appointment-status-cell', isScheduled);
    });
};

export const applyWorkspaceChrome = (pageRoot, target, { panelTitle = null } = {}) => {
    const card = pageRoot.querySelector('.dashboard-service-cases-card');

    pageRoot.dataset.liveQueue = target.operationQueue;
    pageRoot.dataset.liveFilter = target.serviceCaseFilter;
    pageRoot.dataset.liveWorkspace = target.workspace;
    pageRoot.dataset.operationsWorkspaceKind = target.kind ?? 'case_queue';
    // Drop Ready ±1 membership memory across queue/tab switches (Phase 2 P1).
    syncReadyQueueMembershipScope(pageRoot);

    if (card && (target.kind ?? 'case_queue') === 'case_queue') {
        card.dataset.operationQueue = target.operationQueue;
        card.dataset.serviceCaseFilter = target.serviceCaseFilter;
        updateQueueChips(card, target.operationQueue);
        updateScheduledChrome(card, target.operationQueue);
    }

    if (panelTitle) {
        const title = pageRoot.querySelector('.dashboard-cases-title');

        if (title) {
            title.textContent = panelTitle;
        }
    }
};

const clearDashboardSearchUi = (pageRoot) => {
    if (isDashboardSearchActive()) {
        setDashboardSearchActive(false);
        pageRoot.querySelector('[data-dashboard-search-banner]')?.classList.add('d-none');
    }

    setServiceCaseSearchQuery('');
};

const resolvePanelTitle = (card, target) => {
    if (target.kind === 'embedded') {
        return target.workspace === 'refunds' ? 'Refund Queue' : 'Active Service Cases';
    }

    const activeChip = card?.querySelector(
        `.dashboard-case-filter-chip[data-workspace="${target.operationQueue}"] .dashboard-case-filter-chip__label`,
    );

    if (activeChip?.textContent?.trim()) {
        return activeChip.textContent.trim();
    }

    if (target.serviceCaseFilter === 'my_attention') {
        return 'My Attention';
    }

    if (target.serviceCaseFilter === 'needs_attention') {
        return 'Needs Attention';
    }

    return null;
};

const pauseCaseLiveUpdates = (pageRoot) => {
    if (pageRoot.dataset.operationsEmbeddedActive === '1') {
        return;
    }

    pageRoot.dataset.operationsEmbeddedActive = '1';
    pageRoot.dataset.liveUpdatesPausedForEmbed = pageRoot.dataset.liveUpdatesEnabled ?? '1';
    pageRoot.dataset.liveUpdatesEnabled = '0';
    stopPolling();
};

const resumeCaseLiveUpdates = (pageRoot) => {
    if (pageRoot.dataset.operationsEmbeddedActive !== '1') {
        return;
    }

    const previous = pageRoot.dataset.liveUpdatesPausedForEmbed ?? '1';
    pageRoot.dataset.liveUpdatesEnabled = previous;
    delete pageRoot.dataset.operationsEmbeddedActive;
    delete pageRoot.dataset.liveUpdatesPausedForEmbed;

    if (previous !== '0' && (pageRoot.dataset.liveMode ?? 'poll') === 'poll') {
        startPolling(pageRoot);
    }
};

const showCaseHost = (pageRoot) => {
    const caseHost = pageRoot.querySelector('[data-operations-case-host]');
    const embeddedHost = pageRoot.querySelector('[data-operations-embedded-host]');

    caseHost?.removeAttribute('hidden');
    if (embeddedHost) {
        embeddedHost.hidden = true;
        embeddedHost.innerHTML = '';
    }
};

const showEmbeddedHost = (pageRoot, html) => {
    const caseHost = pageRoot.querySelector('[data-operations-case-host]');
    const embeddedHost = pageRoot.querySelector('[data-operations-embedded-host]');

    if (caseHost) {
        caseHost.hidden = true;
    }

    if (embeddedHost) {
        embeddedHost.hidden = false;
        embeddedHost.innerHTML = html;
    }
};

const fetchEmbeddedPanel = async (pageRoot, target) => {
    const workspaceUrl = pageRoot.dataset.operationsWorkspaceUrl;

    if (!workspaceUrl) {
        return null;
    }

    const query = new URLSearchParams();
    query.set('workspace', target.workspace);

    const sourceParams = target.query ?? Object.fromEntries(target.url?.searchParams?.entries?.() ?? []);
    Object.entries(sourceParams).forEach(([key, value]) => {
        if (key === 'workspace' || value === undefined || value === null || value === '') {
            return;
        }

        query.set(key, String(value));
    });

    if (target.workspace === 'active_cases' && !query.has('status')) {
        query.set('status', 'active');
    }

    if (target.workspace === 'refunds' && !query.has('status') && !query.has('queue')) {
        query.set('status', 'pending');
    }

    const response = await fetch(`${workspaceUrl}?${query.toString()}`, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        return null;
    }

    return response.json();
};

const embeddedFallbackDashboardUrl = (target) => {
    const href = typeof target?.url?.href === 'string' ? target.url.href : null;

    if (href && href.includes('/dashboard')) {
        return href;
    }

    const extraParams = { ...(target?.query ?? {}) };
    delete extraParams.workspace;

    return buildWorkspaceHistoryUrl(target?.workspace, {
        pathname: window.location.pathname.endsWith('/dashboard')
            ? window.location.pathname
            : '/dashboard',
        search: window.location.pathname.endsWith('/dashboard') ? window.location.search : '',
        extraParams,
    });
};

const switchToEmbeddedWorkspace = async (pageRoot, target, { pushHistory = true } = {}) => {
    pauseCaseLiveUpdates(pageRoot);
    applyWorkspaceChrome(pageRoot, {
        ...target,
        kind: 'embedded',
    }, {
        panelTitle: resolvePanelTitle(null, target),
    });

    const primary = pageRoot.querySelector('[data-operations-primary-panel]');
    primary?.classList.add('is-workspace-switching');

    if (pushHistory) {
        const extraParams = { ...(target.query ?? {}) };
        delete extraParams.workspace;
        window.history.pushState(
            { operationsWorkspace: target.workspace },
            '',
            buildWorkspaceHistoryUrl(target.workspace, {
                pathname: window.location.pathname,
                search: window.location.search,
                extraParams,
            }),
        );
    }

    try {
        const data = await fetchEmbeddedPanel(pageRoot, target);

        if (!data?.panel_html) {
            // Stay on Dashboard SSR embed — never bounce to legacy /incidents or /refunds.
            window.location.assign(embeddedFallbackDashboardUrl(target));

            return false;
        }

        showEmbeddedHost(pageRoot, data.panel_html);
        pageRoot.dataset.liveWorkspace = data.workspace;
        pageRoot.dataset.operationsWorkspaceKind = 'embedded';
    } finally {
        primary?.classList.remove('is-workspace-switching');
    }

    primary?.scrollIntoView({ behavior: 'smooth', block: 'start' });

    return true;
};

const switchToCaseWorkspace = async (
    pageRoot,
    target,
    {
        pushHistory = true,
        clearQuickFilter = null,
    } = {},
) => {
    const wasEmbedded = pageRoot.dataset.operationsWorkspaceKind === 'embedded'
        || pageRoot.dataset.operationsEmbeddedActive === '1';

    if (wasEmbedded) {
        showCaseHost(pageRoot);
        resumeCaseLiveUpdates(pageRoot);
    }

    const card = pageRoot.querySelector('.dashboard-service-cases-card');
    clearQuickFilter?.();
    clearDashboardSearchUi(pageRoot);

    const panelTitle = resolvePanelTitle(card, target);
    applyWorkspaceChrome(pageRoot, { ...target, kind: 'case_queue' }, { panelTitle });
    setServiceCasePagination({ loaded: 0, total: Number(card?.dataset.serviceCaseFilterTotal ?? 0) });
    setSkeletonVisible(card, true);

    if (pushHistory) {
        window.history.pushState(
            { operationsWorkspace: target.workspace },
            '',
            buildWorkspaceHistoryUrl(target.workspace, {
                pathname: window.location.pathname,
                search: window.location.search,
            }),
        );
    }

    try {
        const data = await refreshDashboard(pageRoot, 'operations_workspace_switch', {
            force: true,
            resetPagination: true,
        });

        if (!data) {
            window.location.assign(target.url?.href ?? buildWorkspaceHistoryUrl(target.workspace));

            return false;
        }

        if (data.operation_queue || data.service_case_filter || data.workspace) {
            applyWorkspaceChrome(pageRoot, {
                workspace: data.workspace ?? target.workspace,
                kind: 'case_queue',
                operationQueue: data.operation_queue ?? target.operationQueue,
                serviceCaseFilter: data.service_case_filter ?? target.serviceCaseFilter,
            }, {
                panelTitle: data.panel_title ?? panelTitle,
            });

            if (data.live_scope) {
                pageRoot.dataset.liveScope = data.live_scope;
            }
        }
    } finally {
        setSkeletonVisible(card, false);
    }

    document.getElementById('dashboard-service-cases-panel')
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });

    return true;
};

export const switchOperationsWorkspace = async (
    pageRoot,
    target,
    {
        pushHistory = true,
        clearQuickFilter = null,
        onComplete = null,
    } = {},
) => {
    if (!pageRoot || !target) {
        return false;
    }

    const kind = target.kind ?? (isEmbeddedWorkspace(target.workspace) ? 'embedded' : 'case_queue');
    const normalized = { ...target, kind };

    const currentWorkspace = pageRoot.dataset.liveWorkspace
        ?? pageRoot.dataset.liveFilter
        ?? pageRoot.dataset.liveQueue;
    const currentKind = pageRoot.dataset.operationsWorkspaceKind ?? 'case_queue';

    if (
        currentWorkspace === normalized.workspace
        && currentKind === kind
        && kind === 'case_queue'
        && pageRoot.dataset.liveQueue === normalized.operationQueue
        && pageRoot.dataset.liveFilter === normalized.serviceCaseFilter
    ) {
        document.getElementById('dashboard-service-cases-panel')
            ?.scrollIntoView({ behavior: 'smooth', block: 'start' });

        return true;
    }

    if (
        currentWorkspace === normalized.workspace
        && currentKind === 'embedded'
        && kind === 'embedded'
    ) {
        const currentSearch = window.location.search;
        const nextSearch = normalized.url
            ? new URL(normalized.url, window.location.origin).search
            : buildWorkspaceHistoryUrl(normalized.workspace, {
                pathname: window.location.pathname,
                extraParams: normalized.query ?? {},
            }).replace(/^[^?]*/, '');

        if (currentSearch === nextSearch || currentSearch === `?${nextSearch.replace(/^\?/, '')}`) {
            pageRoot.querySelector('[data-operations-primary-panel]')
                ?.scrollIntoView({ behavior: 'smooth', block: 'start' });

            return true;
        }
    }

    const ok = kind === 'embedded'
        ? await switchToEmbeddedWorkspace(pageRoot, normalized, { pushHistory })
        : await switchToCaseWorkspace(pageRoot, normalized, { pushHistory, clearQuickFilter });

    if (ok) {
        onComplete?.(normalized);
    }

    return ok;
};

const bindEmbeddedInteractions = (pageRoot, clearQuickFilter) => {
    pageRoot.addEventListener('submit', async (event) => {
        const form = event.target.closest('[data-operations-embedded-form]');

        if (!form || !pageRoot.contains(form)) {
            return;
        }

        event.preventDefault();

        const workspace = form.dataset.operationsEmbeddedForm;
        const params = Object.fromEntries(new FormData(form).entries());

        await switchOperationsWorkspace(pageRoot, {
            workspace,
            kind: 'embedded',
            operationQueue: READY_QUEUE,
            serviceCaseFilter: READY_QUEUE,
            query: params,
            url: new URL(buildWorkspaceHistoryUrl(workspace, {
                pathname: window.location.pathname,
                extraParams: params,
            }), window.location.origin),
        }, {
            pushHistory: true,
            clearQuickFilter,
        });
    });

    pageRoot.addEventListener('click', async (event) => {
        const nav = event.target.closest('[data-operations-embedded-nav], [data-operations-embedded-clear]');
        const paginationLink = event.target.closest('[data-operations-embedded-pagination] a');

        const link = nav || paginationLink;

        if (!link || !pageRoot.contains(link)) {
            return;
        }

        const href = link.getAttribute('href');

        if (!href) {
            return;
        }

        event.preventDefault();

        const target = parseDashboardNavigationTarget(href, '/dashboard')
            ?? {
                workspace: link.dataset.operationsEmbeddedNav
                    || link.dataset.operationsEmbeddedClear
                    || pageRoot.dataset.liveWorkspace,
                kind: 'embedded',
                operationQueue: READY_QUEUE,
                serviceCaseFilter: READY_QUEUE,
                url: new URL(href, window.location.origin),
                query: Object.fromEntries(new URL(href, window.location.origin).searchParams.entries()),
            };

        target.kind = 'embedded';

        await switchOperationsWorkspace(pageRoot, target, {
            pushHistory: true,
            clearQuickFilter,
        });
    });
};

export const initOperationsWorkspaceSoftSwitch = ({
    pageRoot,
    clearQuickFilter = null,
} = {}) => {
    if (!pageRoot || pageRoot.dataset.operationsWorkspaceSoftSwitch !== '1') {
        return null;
    }

    const dashboardPath = pageRoot.dataset.dashboardPath
        ?? new URL(pageRoot.dataset.liveUrl || '/dashboard/live', window.location.origin).pathname.replace(/\/live$/, '')
        ?? '/dashboard';

    const phase2Enabled = pageRoot.dataset.operationsWorkspacePhase2Embed !== '0';

    let switching = false;

    if (pageRoot.dataset.operationsWorkspaceKind === 'embedded') {
        pauseCaseLiveUpdates(pageRoot);
    }

    bindEmbeddedInteractions(pageRoot, clearQuickFilter);

    const handleNavigation = async (event, href, explicitWorkspace = null) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        let target = parseDashboardNavigationTarget(href, dashboardPath)
            ?? (phase2Enabled ? parseLegacyEmbeddedNavigationTarget(href) : null);

        if (!target && explicitWorkspace && phase2Enabled && isEmbeddedWorkspace(explicitWorkspace)) {
            target = {
                workspace: explicitWorkspace,
                kind: 'embedded',
                operationQueue: READY_QUEUE,
                serviceCaseFilter: READY_QUEUE,
                url: new URL(href, window.location.origin),
                query: Object.fromEntries(new URL(href, window.location.origin).searchParams.entries()),
            };
        }

        if (!target) {
            return;
        }

        if (explicitWorkspace) {
            target.workspace = explicitWorkspace;

            if (isEmbeddedWorkspace(explicitWorkspace)) {
                if (!phase2Enabled) {
                    return;
                }

                target.kind = 'embedded';
            } else if (FILTER_WORKSPACES.has(explicitWorkspace)) {
                target.kind = 'case_queue';
                target.serviceCaseFilter = explicitWorkspace;
            } else {
                target.kind = 'case_queue';
                target.operationQueue = explicitWorkspace;
                target.serviceCaseFilter = explicitWorkspace;
            }
        }

        if (target.kind === 'embedded' && !phase2Enabled) {
            return;
        }

        event.preventDefault();

        if (switching) {
            return;
        }

        switching = true;

        try {
            await switchOperationsWorkspace(pageRoot, target, {
                pushHistory: true,
                clearQuickFilter,
            });
        } finally {
            switching = false;
        }
    };

    pageRoot.addEventListener('click', (event) => {
        const softLink = event.target.closest('[data-operations-workspace-link]');

        if (softLink && pageRoot.contains(softLink)) {
            const href = softLink.getAttribute('href');

            if (href) {
                handleNavigation(event, href, softLink.dataset.workspace ?? null);
            }

            return;
        }

        // Defense-in-depth: stale KPI markup may still point at /incidents or /refunds
        // without data-operations-workspace-link. Intercept those when Phase 2 is on.
        if (!phase2Enabled) {
            return;
        }

        const legacyKpi = event.target.closest('#dashboard-kpi-strip a.dashboard-kpi-item, a.dashboard-kpi-item');

        if (!legacyKpi || !pageRoot.contains(legacyKpi)) {
            return;
        }

        const href = legacyKpi.getAttribute('href');

        if (!href) {
            return;
        }

        const legacyTarget = parseLegacyEmbeddedNavigationTarget(href);

        if (!legacyTarget) {
            return;
        }

        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        event.preventDefault();

        if (switching) {
            return;
        }

        switching = true;

        switchOperationsWorkspace(pageRoot, legacyTarget, {
            pushHistory: true,
            clearQuickFilter,
        }).finally(() => {
            switching = false;
        });
    });

    window.addEventListener('popstate', () => {
        if (switching) {
            return;
        }

        const target = parseDashboardNavigationTarget(window.location.href, dashboardPath);

        if (!target) {
            return;
        }

        switching = true;
        switchOperationsWorkspace(pageRoot, target, {
            pushHistory: false,
            clearQuickFilter,
        }).finally(() => {
            switching = false;
        });
    });

    return {
        switchTo: (target, options = {}) => switchOperationsWorkspace(pageRoot, target, {
            clearQuickFilter,
            ...options,
        }),
    };
};
