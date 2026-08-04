import { refreshDashboard } from './live-dashboard';
import { setDashboardSearchActive, isDashboardSearchActive } from './dashboard-search-mode';
import { setServiceCasePagination, setServiceCaseSearchQuery } from './dashboard-service-case-state';

const FILTER_WORKSPACES = new Set([
    'overdue',
    'warning',
    'my_attention',
    'needs_attention',
    'high_priority',
    'pending_support',
]);

const SCHEDULED_QUEUE = 'scheduled';
const READY_QUEUE = 'action_required';

export const parseDashboardNavigationTarget = (href, currentPath = '/dashboard') => {
    let url;

    try {
        url = new URL(href, window.location.origin);
    } catch (error) {
        return null;
    }

    if (url.pathname !== currentPath && !url.pathname.endsWith('/dashboard')) {
        return null;
    }

    const workspace = url.searchParams.get('workspace');
    const queue = url.searchParams.get('queue');
    const filter = url.searchParams.get('filter');
    const view = url.searchParams.get('view');

    if (!workspace && !queue && !filter && !view) {
        return {
            workspace: READY_QUEUE,
            operationQueue: READY_QUEUE,
            serviceCaseFilter: READY_QUEUE,
            url,
        };
    }

    if (workspace && FILTER_WORKSPACES.has(workspace)) {
        return {
            workspace,
            operationQueue: queue || (workspace === 'my_attention' ? 'my_work' : READY_QUEUE),
            serviceCaseFilter: workspace,
            url,
        };
    }

    if (workspace) {
        return {
            workspace,
            operationQueue: workspace,
            serviceCaseFilter: filter || workspace,
            url,
        };
    }

    if (filter && FILTER_WORKSPACES.has(filter)) {
        return {
            workspace: filter,
            operationQueue: queue || (filter === 'my_attention' ? 'my_work' : READY_QUEUE),
            serviceCaseFilter: filter,
            url,
        };
    }

    if (queue) {
        return {
            workspace: queue,
            operationQueue: queue,
            serviceCaseFilter: filter || queue,
            url,
        };
    }

    if (view) {
        return {
            workspace: view,
            operationQueue: view,
            serviceCaseFilter: filter || view,
            url,
        };
    }

    return null;
};

export const buildWorkspaceHistoryUrl = (workspace, { pathname = '/dashboard', search = '' } = {}) => {
    const url = new URL(pathname, window.location.origin);
    const current = new URLSearchParams(search);

    // Preserve non-navigation query params (e.g. q is cleared on switch; keep open_customer_360 out).
    ['workspace', 'queue', 'filter', 'view'].forEach((key) => current.delete(key));

    if (workspace) {
        url.searchParams.set('workspace', workspace);
    }

    current.forEach((value, key) => {
        if (key === 'q' || key === 'open_customer_360' || key === 'open_more_menu') {
            return;
        }

        url.searchParams.set(key, value);
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

    if (card) {
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

    const card = pageRoot.querySelector('.dashboard-service-cases-card');
    const currentWorkspace = pageRoot.dataset.liveWorkspace
        ?? pageRoot.dataset.liveFilter
        ?? pageRoot.dataset.liveQueue;

    if (
        currentWorkspace === target.workspace
        && pageRoot.dataset.liveQueue === target.operationQueue
        && pageRoot.dataset.liveFilter === target.serviceCaseFilter
    ) {
        document.getElementById('dashboard-service-cases-panel')
            ?.scrollIntoView({ behavior: 'smooth', block: 'start' });

        return true;
    }

    clearQuickFilter?.();
    clearDashboardSearchUi(pageRoot);

    const panelTitle = resolvePanelTitle(card, target);
    applyWorkspaceChrome(pageRoot, target, { panelTitle });
    setServiceCasePagination({ loaded: 0, total: Number(card?.dataset.serviceCaseFilterTotal ?? 0) });
    setSkeletonVisible(card, true);

    if (pushHistory) {
        const historyUrl = buildWorkspaceHistoryUrl(target.workspace, {
            pathname: window.location.pathname,
            search: window.location.search,
        });
        window.history.pushState(
            { operationsWorkspace: target.workspace },
            '',
            historyUrl,
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

    onComplete?.(target);

    return true;
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

    let switching = false;

    const handleNavigation = async (event, href, explicitWorkspace = null) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        const target = parseDashboardNavigationTarget(href, dashboardPath);

        if (!target) {
            return;
        }

        if (explicitWorkspace) {
            target.workspace = explicitWorkspace;

            if (FILTER_WORKSPACES.has(explicitWorkspace)) {
                target.serviceCaseFilter = explicitWorkspace;
            } else {
                target.operationQueue = explicitWorkspace;
                target.serviceCaseFilter = explicitWorkspace;
            }
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
        const link = event.target.closest('[data-operations-workspace-link]');

        if (!link || !pageRoot.contains(link)) {
            return;
        }

        const href = link.getAttribute('href');

        if (!href) {
            return;
        }

        handleNavigation(event, href, link.dataset.workspace ?? null);
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
