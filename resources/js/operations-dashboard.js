const SECTION_TARGETS = {
    critical_alerts: 'operations-critical-alerts',
    overview_cards: 'operations-overview-cards',
    ira_briefing_compact: 'operations-ira-briefing-compact',
    ira_full_analysis: 'operations-ira-full-analysis-modal-body',
    health_status: 'operations-health-status',
    today_tab: 'operations-tab-today-content',
    team_tab: 'operations-tab-team-content',
    performance_tab: 'operations-tab-performance-content',
    system_tab: 'operations-tab-system-content',
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

const HEALTH_DETAIL_GROUPS = {
    cashfree_health: 'health_cashfree',
    radiumbox_health: 'health_radiumbox',
    team_telegram_status: 'health_telegram',
};

const HEALTH_DETAIL_TARGETS = {
    cashfree_health: 'operations-health-detail-cashfree',
    radiumbox_health: 'operations-health-detail-radiumbox',
    team_telegram_status: 'operations-health-detail-telegram',
};

const ALWAYS_REFRESH_GROUPS = ['critical', 'summary', 'health', 'ira_compact'];
const TAB_GROUP_BY_PANE = {
    'operations-pane-today': 'today',
    'operations-pane-team': 'team',
    'operations-pane-performance': 'performance',
    'operations-pane-system': 'system',
};

const TAB_CONTENT_TARGETS = {
    today: 'operations-tab-today-content',
    team: 'operations-tab-team-content',
    performance: 'operations-tab-performance-content',
    system: 'operations-tab-system-content',
};

const TAB_SECTION_KEYS = {
    today: 'today_tab',
    team: 'team_tab',
    performance: 'performance_tab',
    system: 'system_tab',
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

const buildLiveGroups = (pageRoot, forceFullRefresh, extraGroups = []) => {
    if (forceFullRefresh) {
        return null;
    }

    const groups = [...ALWAYS_REFRESH_GROUPS];
    const activeGroup = getActiveTabGroup(pageRoot);

    if (activeGroup) {
        groups.push(activeGroup);
    }

    extraGroups.forEach((group) => {
        if (!groups.includes(group)) {
            groups.push(group);
        }
    });

    return groups;
};

const fetchLiveGroups = async (pageRoot, groups) => {
    const liveUrl = pageRoot.dataset.liveUrl;

    if (!liveUrl) {
        return null;
    }

    const requestUrl = groups === null
        ? liveUrl
        : `${liveUrl}?groups=${groups.join(',')}`;

    const response = await fetch(requestUrl, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!response.ok) {
        return null;
    }

    return response.json();
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

            if (targetSelector.startsWith('#operations-health')) {
                const healthTarget = pageRoot.querySelector(targetSelector);

                if (healthTarget) {
                    healthTarget.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                    healthTarget.click();
                }

                return;
            }

            const tabButton = pageRoot.querySelector(targetSelector);

            if (!tabButton || !window.bootstrap?.Tab) {
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

const markTabLoaded = (pageRoot, group) => {
    const pane = pageRoot.querySelector(`[data-operations-lazy-group="${group}"]`);

    if (pane) {
        pane.dataset.operationsLazyLoaded = 'true';
    }
};

const loadLazyTab = async (pageRoot, group) => {
    const pane = pageRoot.querySelector(`[data-operations-lazy-group="${group}"]`);

    if (!pane || pane.dataset.operationsLazyLoaded === 'true') {
        return;
    }

    const payload = await fetchLiveGroups(pageRoot, [group]);

    if (!payload) {
        return;
    }

    const sectionKey = TAB_SECTION_KEYS[group];
    const targetId = TAB_CONTENT_TARGETS[group];
    const sectionHtml = payload.html?.[sectionKey];

    if (targetId && sectionHtml !== undefined) {
        replaceSectionHtml(targetId, sectionHtml);
        markTabLoaded(pageRoot, group);
        bindBatchRecoveryForms(pageRoot);
        bindOperationsTabShortcuts(pageRoot);
        bindIraInsightToggleLabels(pageRoot);
    }
};

const loadHealthDetail = async (pageRoot, collapseElement) => {
    const section = collapseElement.dataset.operationsLazySection;
    const targetId = HEALTH_DETAIL_TARGETS[section];
    const group = HEALTH_DETAIL_GROUPS[section];

    if (!section || !targetId || !group || collapseElement.dataset.operationsLazyLoaded === 'true') {
        return;
    }

    const payload = await fetchLiveGroups(pageRoot, [group]);

    if (!payload) {
        return;
    }

    const sectionHtml = payload.html?.[section];

    if (sectionHtml !== undefined) {
        replaceSectionHtml(targetId, sectionHtml);
        collapseElement.dataset.operationsLazyLoaded = 'true';
        bindBatchRecoveryForms(pageRoot);
        bindOperationsTabShortcuts(pageRoot);
    }
};

const bindHealthAccordionLazyLoad = (pageRoot) => {
    pageRoot.querySelectorAll('[data-operations-lazy-section]').forEach((collapseElement) => {
        if (collapseElement.dataset.operationsHealthLazyBound === 'true') {
            return;
        }

        collapseElement.dataset.operationsHealthLazyBound = 'true';

        collapseElement.addEventListener('show.bs.collapse', () => {
            loadHealthDetail(pageRoot, collapseElement);
        });

        if (collapseElement.classList.contains('show')) {
            loadHealthDetail(pageRoot, collapseElement);
        }
    });
};

const bindIraFullAnalysisModal = (pageRoot) => {
    const modalElement = document.getElementById('operations-ira-full-analysis-modal');

    if (!modalElement || modalElement.dataset.operationsIraModalBound === 'true') {
        return;
    }

    modalElement.dataset.operationsIraModalBound = 'true';

    modalElement.addEventListener('show.bs.modal', async () => {
        if (modalElement.dataset.operationsIraAnalysisLoaded === 'true') {
            return;
        }

        const payload = await fetchLiveGroups(pageRoot, ['ira_full']);

        if (!payload) {
            return;
        }

        applyLiveHtml(payload.html ?? {});
        modalElement.dataset.operationsIraAnalysisLoaded = 'true';
        bindBatchRecoveryForms(pageRoot);
        bindOperationsTabShortcuts(pageRoot);
        bindIraInsightToggleLabels(pageRoot);
    });
};

const refreshOperationsDashboard = async (pageRoot, { forceFullRefresh = false, extraGroups = [] } = {}) => {
    const groups = buildLiveGroups(pageRoot, forceFullRefresh, extraGroups);

    try {
        const payload = await fetchLiveGroups(pageRoot, groups);

        if (!payload) {
            return;
        }

        applyLiveHtml(payload.html ?? {});

        bindBatchRecoveryForms(pageRoot);
        bindOperationsTabShortcuts(pageRoot);
        bindIraInsightToggleLabels(pageRoot);
        bindHealthAccordionLazyLoad(pageRoot);

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
            const group = tabButton.dataset.operationsLiveGroup;

            if (group) {
                loadLazyTab(pageRoot, group);
            }

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
                bindHealthAccordionLazyLoad(pageRoot);
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
    bindHealthAccordionLazyLoad(pageRoot);
    bindIraFullAnalysisModal(pageRoot);

    refreshOperationsDashboard(pageRoot);
    loadLazyTab(pageRoot, 'today');

    const intervalMs = Number(pageRoot.dataset.liveInterval ?? 30000);
    const fullRefreshIntervalMs = Number(pageRoot.dataset.liveFullInterval ?? 120000);
    startPolling(pageRoot, intervalMs, fullRefreshIntervalMs);
};

export { refreshOperationsDashboard, initOperationsDashboard };
