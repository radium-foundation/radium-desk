const SECTION_TARGETS = {
    critical_alerts: 'operations-critical-alerts',
    overview_cards: 'operations-overview-cards',
    health_status: 'operations-health-status',
    support_intelligence: 'operations-support-intelligence',
    ira_briefing: 'operations-ira-briefing',
    ira_briefing_details: 'operations-ira-briefing-details',
    immediate_risks: 'operations-immediate-risks',
    advisor_insights: 'operations-advisor-insights',
    team_availability: 'operations-team-availability',
    team_telegram_status: 'operations-team-telegram-status',
    system_health: 'operations-system-health',
    notification_metrics: 'operations-notification-metrics',
    automation_metrics: 'operations-automation-metrics',
    queue_metrics: 'operations-queue-metrics',
    integration_health: 'operations-integration-health',
    radiumbox_health: 'operations-radiumbox-health',
    cashfree_health: 'operations-cashfree-health',
    cashfree_device_enrichment_quality: 'operations-cashfree-device-enrichment-quality',
    missing_serial_automation_quality: 'operations-missing-serial-automation-quality',
    recent_notification_failures: 'operations-recent-notification-failures',
    recent_automation_activity: 'operations-recent-automation-activity',
    recent_ira_messages: 'operations-recent-ira-messages',
};

const ALWAYS_REFRESH_GROUPS = ['critical', 'summary', 'health'];
const TAB_GROUP_BY_PANE = {
    'operations-pane-today': 'today',
    'operations-pane-team': 'team',
    'operations-pane-performance': 'performance',
    'operations-pane-system': 'system',
};

const replaceSectionHtml = (elementId, html) => {
    const element = document.getElementById(elementId);

    if (!element || html === undefined) {
        return;
    }

    element.innerHTML = html;
};

const applyLiveHtml = (html) => {
    Object.entries(html ?? {}).forEach(([sectionKey, sectionHtml]) => {
        const elementId = SECTION_TARGETS[sectionKey];

        if (elementId) {
            replaceSectionHtml(elementId, sectionHtml);
        }
    });
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

const getActiveTabGroup = (pageRoot) => {
    const activePane = pageRoot.querySelector('.tab-pane.active');

    if (!activePane?.id) {
        return null;
    }

    return TAB_GROUP_BY_PANE[activePane.id] ?? null;
};

const buildLiveGroups = (pageRoot, forceFullRefresh) => {
    if (forceFullRefresh) {
        return null;
    }

    const groups = [...ALWAYS_REFRESH_GROUPS];
    const activeGroup = getActiveTabGroup(pageRoot);

    if (activeGroup) {
        groups.push(activeGroup);
    }

    return groups;
};

const bindOperationsTabShortcuts = (pageRoot) => {
    pageRoot.querySelectorAll('[data-operations-tab-target]').forEach((trigger) => {
        if (trigger.dataset.operationsTabBound === 'true') {
            return;
        }

        trigger.dataset.operationsTabBound = 'true';

        trigger.addEventListener('click', () => {
            const targetSelector = trigger.dataset.operationsTabTarget;

            if (!targetSelector) {
                return;
            }

            const tabButton = pageRoot.querySelector(targetSelector);

            if (!tabButton || !window.bootstrap?.Tab) {
                if (targetSelector.startsWith('#operations-health-trigger-')) {
                    const healthTrigger = pageRoot.querySelector(targetSelector);

                    if (healthTrigger) {
                        healthTrigger.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                        healthTrigger.click();
                    }
                }

                return;
            }

            window.bootstrap.Tab.getOrCreateInstance(tabButton).show();
            tabButton.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        });
    });
};

const bindIraInsightToggleLabels = (pageRoot) => {
    pageRoot.querySelectorAll('[data-operations-view-all-label]').forEach((trigger) => {
        if (trigger.dataset.operationsInsightToggleBound === 'true') {
            return;
        }

        trigger.dataset.operationsInsightToggleBound = 'true';

        const viewAllLabel = trigger.dataset.operationsViewAllLabel ?? 'View all insights';
        const viewLessLabel = trigger.dataset.operationsViewLessLabel ?? 'Show fewer insights';
        const targetSelector = trigger.dataset.bsTarget;
        const collapseTarget = targetSelector ? pageRoot.querySelector(targetSelector) : null;

        if (!collapseTarget) {
            return;
        }

        collapseTarget.addEventListener('show.bs.collapse', () => {
            trigger.textContent = viewLessLabel;
            trigger.setAttribute('aria-expanded', 'true');
        });

        collapseTarget.addEventListener('hide.bs.collapse', () => {
            trigger.textContent = viewAllLabel;
            trigger.setAttribute('aria-expanded', 'false');
        });
    });
};

const refreshOperationsDashboard = async (pageRoot, { forceFullRefresh = false } = {}) => {
    const liveUrl = pageRoot.dataset.liveUrl;

    if (!liveUrl) {
        return;
    }

    const groups = buildLiveGroups(pageRoot, forceFullRefresh);
    const requestUrl = groups === null
        ? liveUrl
        : `${liveUrl}?groups=${groups.join(',')}`;

    try {
        const response = await fetch(requestUrl, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            return;
        }

        const payload = await response.json();

        applyLiveHtml(payload.html ?? {});

        bindBatchRecoveryForms(pageRoot);
        bindOperationsTabShortcuts(pageRoot);
        bindIraInsightToggleLabels(pageRoot);

        const generatedAtElement = document.getElementById('operations-dashboard-generated-at');

        if (generatedAtElement && payload.generated_at) {
            generatedAtElement.textContent = `Updated ${formatGeneratedAt(payload.generated_at)}`;
        }
    } catch {
        // Keep the last rendered snapshot when refresh fails.
    }
};

let pollIntervalId = null;
let pollCount = 0;

const startPolling = (pageRoot, intervalMs, fullRefreshIntervalMs) => {
    if (pollIntervalId !== null) {
        return;
    }

    pollIntervalId = window.setInterval(() => {
        pollCount += 1;
        const shouldForceFullRefresh = fullRefreshIntervalMs > 0
            && pollCount * intervalMs >= fullRefreshIntervalMs;

        refreshOperationsDashboard(pageRoot, { forceFullRefresh: shouldForceFullRefresh });

        if (shouldForceFullRefresh) {
            pollCount = 0;
        }
    }, intervalMs);

    pageRoot.querySelectorAll('[data-operations-live-group]').forEach((tabButton) => {
        tabButton.addEventListener('shown.bs.tab', () => {
            refreshOperationsDashboard(pageRoot);
        });
    });
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

                applyLiveHtml(payload.html ?? {});
                bindBatchRecoveryForms(pageRoot);
                bindOperationsTabShortcuts(pageRoot);
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
    bindOperationsTabShortcuts(pageRoot);
    bindIraInsightToggleLabels(pageRoot);

    const intervalMs = Number(pageRoot.dataset.liveInterval ?? 30000);
    const fullRefreshIntervalMs = Number(pageRoot.dataset.liveFullInterval ?? 120000);
    startPolling(pageRoot, intervalMs, fullRefreshIntervalMs);
};

export { refreshOperationsDashboard, initOperationsDashboard };
