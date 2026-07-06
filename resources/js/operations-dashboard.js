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

const TAB_GROUP_BY_SECTION = {
    today_tab: 'today',
    team_tab: 'team',
    performance_tab: 'performance',
    system_tab: 'system',
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
        return false;
    }

    element.innerHTML = html;

    return true;
};

const renderLazyLoadError = (message, retryLabel = 'Retry') => `
    <div class="operations-lazy-error alert alert-warning mb-0 d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-2">
        <span>${message}</span>
        <button type="button" class="btn btn-sm btn-outline-warning" data-operations-lazy-retry>
            ${retryLabel}
        </button>
    </div>
`;

const showLazyLoadError = (elementId, message, retryHandler) => {
    const element = document.getElementById(elementId);

    if (!element) {
        return;
    }

    element.innerHTML = renderLazyLoadError(message);
    element.querySelector('[data-operations-lazy-retry]')?.addEventListener('click', retryHandler);
};

const applyLiveHtml = (pageRoot, html) => {
    Object.entries(html ?? {}).forEach(([sectionKey, sectionHtml]) => {
        const elementId = SECTION_TARGETS[sectionKey];

        if (!elementId) {
            return;
        }

        replaceSectionHtml(elementId, sectionHtml);

        const tabGroup = TAB_GROUP_BY_SECTION[sectionKey];

        if (tabGroup) {
            markTabLoaded(pageRoot, tabGroup);
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
        throw new Error('Operations live refresh URL is missing.');
    }

    const requestUrl = groups === null
        ? liveUrl
        : `${liveUrl}?groups=${groups.join(',')}`;

    const response = await fetch(requestUrl, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!response.ok) {
        throw new Error(`Operations live refresh failed (${response.status}).`);
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

const resetTabLoaded = (pageRoot, group) => {
    const pane = pageRoot.querySelector(`[data-operations-lazy-group="${group}"]`);

    if (pane) {
        pane.dataset.operationsLazyLoaded = 'false';
    }
};

const loadLazyTab = async (pageRoot, group, { force = false } = {}) => {
    const pane = pageRoot.querySelector(`[data-operations-lazy-group="${group}"]`);
    const targetId = TAB_CONTENT_TARGETS[group];
    const sectionKey = TAB_SECTION_KEYS[group];

    if (!pane || !targetId || !sectionKey) {
        return false;
    }

    if (!force && pane.dataset.operationsLazyLoaded === 'true') {
        return true;
    }

    try {
        const payload = await fetchLiveGroups(pageRoot, [group]);
        const sectionHtml = payload.html?.[sectionKey];

        if (typeof sectionHtml !== 'string' || sectionHtml.trim() === '') {
            throw new Error(`Missing ${sectionKey} content from operations live refresh.`);
        }

        replaceSectionHtml(targetId, sectionHtml);
        markTabLoaded(pageRoot, group);
        bindBatchRecoveryForms(pageRoot);
        bindOperationsTabShortcuts(pageRoot);
        bindIraInsightToggleLabels(pageRoot);

        return true;
    } catch (error) {
        resetTabLoaded(pageRoot, group);
        showLazyLoadError(
            targetId,
            error instanceof Error ? error.message : 'Unable to load this tab right now.',
            () => {
                replaceSectionHtml(
                    targetId,
                    '<div class="operations-lazy-placeholder card border-0 shadow-sm"><div class="card-body py-4 text-center text-muted"><div class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></div><span>Retrying…</span></div></div>',
                );
                loadLazyTab(pageRoot, group, { force: true });
            },
        );

        return false;
    }
};

const loadHealthDetail = async (pageRoot, collapseElement, { force = false } = {}) => {
    const section = collapseElement.dataset.operationsLazySection;
    const targetId = HEALTH_DETAIL_TARGETS[section];
    const group = HEALTH_DETAIL_GROUPS[section];

    if (!section || !targetId || !group) {
        return false;
    }

    if (!force && collapseElement.dataset.operationsLazyLoaded === 'true') {
        return true;
    }

    try {
        const payload = await fetchLiveGroups(pageRoot, [group]);
        const sectionHtml = payload.html?.[section];

        if (typeof sectionHtml !== 'string' || sectionHtml.trim() === '') {
            throw new Error(`Missing ${section} content from operations live refresh.`);
        }

        replaceSectionHtml(targetId, sectionHtml);
        collapseElement.dataset.operationsLazyLoaded = 'true';
        bindBatchRecoveryForms(pageRoot);
        bindOperationsTabShortcuts(pageRoot);

        return true;
    } catch (error) {
        collapseElement.dataset.operationsLazyLoaded = 'false';
        showLazyLoadError(
            targetId,
            error instanceof Error ? error.message : 'Unable to load integration details right now.',
            () => {
                replaceSectionHtml(
                    targetId,
                    '<div class="operations-lazy-placeholder card border-0 shadow-sm"><div class="card-body py-4 text-center text-muted"><div class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></div><span>Retrying…</span></div></div>',
                );
                loadHealthDetail(pageRoot, collapseElement, { force: true });
            },
        );

        return false;
    }
};

const rehydrateExpandedHealthDetails = (pageRoot) => {
    pageRoot.querySelectorAll('[data-operations-lazy-section].show').forEach((collapseElement) => {
        collapseElement.dataset.operationsLazyLoaded = 'false';
        collapseElement.dataset.operationsHealthLazyBound = 'false';
        loadHealthDetail(pageRoot, collapseElement, { force: true });
    });
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

        const modalBodyId = SECTION_TARGETS.ira_full_analysis;

        try {
            const payload = await fetchLiveGroups(pageRoot, ['ira_full']);
            const sectionHtml = payload.html?.ira_full_analysis;

            if (typeof sectionHtml !== 'string' || sectionHtml.trim() === '') {
                throw new Error('Missing Ira analysis content from operations live refresh.');
            }

            replaceSectionHtml(modalBodyId, sectionHtml);
            modalElement.dataset.operationsIraAnalysisLoaded = 'true';
            bindBatchRecoveryForms(pageRoot);
            bindOperationsTabShortcuts(pageRoot);
            bindIraInsightToggleLabels(pageRoot);
        } catch (error) {
            showLazyLoadError(
                modalBodyId,
                error instanceof Error ? error.message : 'Unable to load Ira analysis right now.',
                () => {
                    modalElement.dataset.operationsIraAnalysisLoaded = 'false';
                    replaceSectionHtml(
                        modalBodyId,
                        '<div class="operations-lazy-placeholder card border-0 shadow-sm"><div class="card-body py-4 text-center text-muted"><div class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></div><span>Retrying…</span></div></div>',
                    );
                    window.bootstrap?.Modal.getOrCreateInstance(modalElement).show();
                },
            );
        }
    });
};

const refreshOperationsDashboard = async (pageRoot, { forceFullRefresh = false, extraGroups = [] } = {}) => {
    const groups = buildLiveGroups(pageRoot, forceFullRefresh, extraGroups);

    try {
        const payload = await fetchLiveGroups(pageRoot, groups);

        applyLiveHtml(pageRoot, payload.html ?? {});

        bindBatchRecoveryForms(pageRoot);
        bindOperationsTabShortcuts(pageRoot);
        bindIraInsightToggleLabels(pageRoot);
        bindHealthAccordionLazyLoad(pageRoot);
        rehydrateExpandedHealthDetails(pageRoot);

        const generatedAtElement = document.getElementById('operations-dashboard-generated-at');

        if (generatedAtElement && payload.generated_at) {
            generatedAtElement.textContent = `Updated ${formatGeneratedAt(payload.generated_at)}`;
        }
    } catch {
        // Keep the last rendered snapshot when background refresh fails.
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
                    credentials: 'same-origin',
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

                applyLiveHtml(pageRoot, payload.html ?? {});
                bindBatchRecoveryForms(pageRoot);
                bindOperationsTabShortcuts(pageRoot);
                bindHealthAccordionLazyLoad(pageRoot);
                rehydrateExpandedHealthDetails(pageRoot);
            } finally {
                if (button instanceof HTMLButtonElement) {
                    button.disabled = false;
                }
            }
        });
    });
};

const initOperationsDashboard = async () => {
    const pageRoot = document.getElementById('operations-dashboard-root');

    if (!pageRoot) {
        return;
    }

    bindBatchRecoveryForms(pageRoot);
    bindOperationsTabShortcuts(pageRoot);
    bindIraInsightToggleLabels(pageRoot);
    bindHealthAccordionLazyLoad(pageRoot);
    bindIraFullAnalysisModal(pageRoot);

    await Promise.all([
        refreshOperationsDashboard(pageRoot),
        loadLazyTab(pageRoot, 'today'),
    ]);

    const intervalMs = Number(pageRoot.dataset.liveInterval ?? 30000);
    const fullRefreshIntervalMs = Number(pageRoot.dataset.liveFullInterval ?? 120000);
    startPolling(pageRoot, intervalMs, fullRefreshIntervalMs);
};

export { refreshOperationsDashboard, initOperationsDashboard };
