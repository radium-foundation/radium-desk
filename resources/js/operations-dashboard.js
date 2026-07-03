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
        replaceSectionHtml('operations-radiumbox-health', html.radiumbox_health);
        replaceSectionHtml('operations-recent-notification-failures', html.recent_notification_failures);
        replaceSectionHtml('operations-recent-automation-activity', html.recent_automation_activity);

        bindBatchRecoveryForms(pageRoot);

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

const bindBatchRecoveryForms = (pageRoot) => {
    pageRoot.querySelectorAll('[data-radiumbox-batch-recovery-form]').forEach((form) => {
        if (form.dataset.batchRecoveryBound === 'true') {
            return;
        }

        form.dataset.batchRecoveryBound = 'true';

        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const recoveryUrl = form.dataset.batchRecoveryUrl?.trim() ?? '';
            const selectedIds = Array.from(form.querySelectorAll('[data-radiumbox-batch-order]:checked'))
                .map((input) => Number.parseInt(input.value, 10))
                .filter((value) => !Number.isNaN(value));

            if (recoveryUrl === '' || selectedIds.length === 0) {
                return;
            }

            const button = form.querySelector('[data-radiumbox-batch-recover-btn]');
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

            if (button instanceof HTMLButtonElement) {
                button.disabled = true;
            }

            try {
                const response = await fetch(recoveryUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
                    },
                    body: JSON.stringify({ order_ids: selectedIds }),
                });

                if (!response.ok) {
                    return;
                }

                const payload = await response.json();

                if (payload.html?.radiumbox_health) {
                    replaceSectionHtml('operations-radiumbox-health', payload.html.radiumbox_health);
                    bindBatchRecoveryForms(pageRoot);
                }
            } finally {
                if (button instanceof HTMLButtonElement) {
                    button.disabled = false;
                }
            }
        });
    });
};

const initOperationsDashboard = () => {
    const pageRoot = document.getElementById('operations-dashboard-root');

    if (!pageRoot) {
        return;
    }

    bindBatchRecoveryForms(pageRoot);

    const intervalMs = Number(pageRoot.dataset.liveInterval ?? 30000);
    startPolling(pageRoot, intervalMs);
};

export { refreshOperationsDashboard, initOperationsDashboard };
