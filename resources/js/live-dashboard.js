import * as bootstrap from 'bootstrap';
import { mergeServiceCaseRows } from './live-dashboard-merge';

const initTooltips = (root = document) => {
    root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((element) => {
        const existing = bootstrap.Tooltip.getInstance(element);

        if (existing) {
            existing.dispose();
        }

        bootstrap.Tooltip.getOrCreateInstance(element);
    });
};

const replaceInnerHtml = (elementId, html) => {
    const element = document.getElementById(elementId);

    if (!element || html === undefined) {
        return;
    }

    element.innerHTML = html;
};

const refreshDashboard = async (pageRoot) => {
    const liveUrl = pageRoot.dataset.liveUrl;
    const filter = pageRoot.dataset.liveFilter ?? 'pending_admin';

    if (!liveUrl || document.hidden) {
        return;
    }

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
            );
        }
    } catch (error) {
        // Ignore transient network errors during background refresh.
    }
};

export const initLiveDashboard = () => {
    const pageRoot = document.getElementById('dashboard-page');

    if (!pageRoot?.dataset.liveUrl) {
        return;
    }

    const intervalMs = Number(pageRoot.dataset.liveInterval ?? 30000);

    window.setInterval(() => {
        refreshDashboard(pageRoot);
    }, intervalMs);
};
