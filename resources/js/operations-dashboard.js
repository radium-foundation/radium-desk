const replaceSectionHtml = (elementId, html) => {
    const element = document.getElementById(elementId);

    if (!element || html === undefined) {
        return;
    }

    element.innerHTML = html;
};

const formatGeneratedAt = (isoString) => {
    if (!isoString) {
        return '';
    }

    const date = new Date(isoString);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return date.toLocaleString();
};

const refreshOperationsDashboard = async (pageRoot) => {
    const liveUrl = pageRoot.dataset.liveUrl;

    if (!liveUrl) {
        return;
    }

    try {
        const response = await fetch(liveUrl, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            return;
        }

        const payload = await response.json();
        const html = payload.html ?? {};

        replaceSectionHtml('operations-advisor-insights', html.advisor_insights);
        replaceSectionHtml('operations-system-health', html.system_health);
        replaceSectionHtml('operations-notification-metrics', html.notification_metrics);
        replaceSectionHtml('operations-automation-metrics', html.automation_metrics);
        replaceSectionHtml('operations-queue-metrics', html.queue_metrics);
        replaceSectionHtml('operations-integration-health', html.integration_health);
        replaceSectionHtml('operations-recent-notification-failures', html.recent_notification_failures);
        replaceSectionHtml('operations-recent-automation-activity', html.recent_automation_activity);

        const generatedAtElement = document.getElementById('operations-dashboard-generated-at');

        if (generatedAtElement && payload.generated_at) {
            generatedAtElement.textContent = `Updated ${formatGeneratedAt(payload.generated_at)}`;
        }
    } catch {
        // Keep the last rendered snapshot when refresh fails.
    }
};

let pollIntervalId = null;

const startPolling = (pageRoot, intervalMs) => {
    if (pollIntervalId !== null) {
        return;
    }

    pollIntervalId = window.setInterval(() => {
        refreshOperationsDashboard(pageRoot);
    }, intervalMs);
};

const initOperationsDashboard = () => {
    const pageRoot = document.getElementById('operations-dashboard-root');

    if (!pageRoot) {
        return;
    }

    const intervalMs = Number(pageRoot.dataset.liveInterval ?? 30000);
    startPolling(pageRoot, intervalMs);
};

initOperationsDashboard();

export { refreshOperationsDashboard };
