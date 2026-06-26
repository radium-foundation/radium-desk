import { mergeServiceCaseRows } from './live-dashboard-merge';
import { initTooltips } from './tooltips';
import { getWorkspaceSession } from './workspace/session';

const replaceInnerHtml = (elementId, html) => {
    const element = document.getElementById(elementId);

    if (!element || html === undefined) {
        return;
    }

    element.innerHTML = html;
};

let refreshInFlight = false;
let pendingDashboardRefresh = null;

const applyDashboardRefresh = (data) => new Promise((resolve) => {
    requestAnimationFrame(() => {
        if (getWorkspaceSession().isActive()) {
            queueDashboardRefresh(data);
            resolve();

            return;
        }

        const lockedIncidentIds = getWorkspaceSession().getLockedIncidentIds();

        replaceInnerHtml('dashboard-action-stats', data.action_stats_html);
        replaceInnerHtml('dashboard-sla-cards', data.sla_cards_html);

        const card = document.querySelector('.dashboard-service-cases-card');

        if (card) {
            mergeServiceCaseRows(
                card,
                data.rows ?? [],
                Boolean(data.service_cases_empty),
                data.service_cases_empty_html ?? '',
                initTooltips,
                { lockedIncidentIds },
            );
        }

        resolve();
    });
});

const queueDashboardRefresh = (data) => {
    pendingDashboardRefresh = data;
};

const flushPendingDashboardRefresh = async () => {
    if (!pendingDashboardRefresh) {
        return;
    }

    const data = pendingDashboardRefresh;
    pendingDashboardRefresh = null;
    await applyDashboardRefresh(data);
};

const refreshDashboard = async (pageRoot) => {
    const liveUrl = pageRoot.dataset.liveUrl;
    const filter = pageRoot.dataset.liveFilter ?? 'pending_admin';

    if (!liveUrl || document.hidden || refreshInFlight) {
        return;
    }

    refreshInFlight = true;

    try {
        const response = await fetch(`${liveUrl}?filter=${encodeURIComponent(filter)}`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            return;
        }

        const data = await response.json();

        if (getWorkspaceSession().isActive()) {
            queueDashboardRefresh(data);

            return;
        }

        await applyDashboardRefresh(data);
    } catch (error) {
        // Ignore transient network errors during background refresh.
    } finally {
        refreshInFlight = false;
    }
};

export const initLiveDashboard = () => {
    const pageRoot = document.getElementById('dashboard-page');

    if (!pageRoot?.dataset.liveUrl) {
        return;
    }

    const session = getWorkspaceSession();

    session.onIdle(() => {
        flushPendingDashboardRefresh();
    });

    const intervalMs = Number(pageRoot.dataset.liveInterval ?? 30000);

    window.setInterval(() => {
        refreshDashboard(pageRoot);
    }, intervalMs);
};

export {
    applyDashboardRefresh,
    flushPendingDashboardRefresh,
    queueDashboardRefresh,
    refreshDashboard,
};
