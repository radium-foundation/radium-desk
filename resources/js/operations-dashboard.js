import { bindAutomationHealthEmbed, initAutomationHealth } from './automation-health';

const SECTION_TARGETS = {
    critical_alerts: 'operations-critical-alerts',
    overview_cards: 'operations-overview-cards',
    ira_compact: 'operations-ira-briefing-compact',
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
    system_health: 'operations-system-health',
    notification_metrics: 'operations-notification-metrics',
    automation_metrics: 'operations-automation-metrics',
    queue_metrics: 'operations-queue-metrics',
    integration_health: 'operations-integration-health',
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
    health_radiumbox: 'health_radiumbox',
    health_telegram: 'health_telegram',
};

const HEALTH_DETAIL_TARGETS = {
    cashfree_health: 'operations-health-detail-cashfree',
    health_radiumbox: 'operations-health-detail-radiumbox',
    health_telegram: 'operations-health-detail-telegram',
};

const ALWAYS_REFRESH_GROUPS = ['critical', 'summary', 'health', 'ira_compact'];
const TAB_GROUP_BY_PANE = {
    'operations-pane-today': 'today',
    'operations-pane-team': 'team',
    'operations-pane-performance': 'performance',
    'operations-pane-system': 'system',
    'operations-pane-automation': 'automation',
};

const TAB_CONTENT_TARGETS = {
    today: 'operations-tab-today-content',
    team: 'operations-tab-team-content',
    performance: 'operations-tab-performance-content',
    system: 'operations-tab-system-content',
    automation: 'operations-tab-automation-content',
};

const TAB_SECTION_KEYS = {
    today: 'today_tab',
    team: 'team_tab',
    performance: 'performance_tab',
    system: 'system_tab',
};

const AUTOMATION_SUBVIEW_TARGETS = {
    health: 'operations-automation-health-content',
    pipeline: 'operations-automation-pipeline-content',
};

const AUTOMATION_EMBED_SELECTORS = {
    health: '[data-automation-health-embed]',
    pipeline: '[data-automation-pipeline-embed]',
};

const FETCH_TIMEOUT_MS = 30000;
const LAZY_LOAD_GUARD_MS = FETCH_TIMEOUT_MS;
const SSR_FRESHNESS_MS = 30000;

const LAZY_PLACEHOLDER_SELECTOR = '.operations-lazy-placeholder';

const renderLazySkeleton = (label = 'Loading…') => `
    <div class="operations-lazy-placeholder operations-skeleton-loader card border-0 shadow-sm" aria-busy="true" aria-label="${label}">
        <div class="card-body py-3">
            <span class="visually-hidden">${label}</span>
            <div class="operations-skeleton-line operations-skeleton-line--title"></div>
            <div class="operations-skeleton-line"></div>
            <div class="operations-skeleton-line operations-skeleton-line--medium"></div>
            <div class="operations-skeleton-line operations-skeleton-line--short"></div>
        </div>
    </div>
`;

const SECTIONS_ALLOW_NESTED_LAZY_MARKERS = new Set([
    'health_status',
]);

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

const isLazyPlaceholderHtml = (html) => {
    if (typeof html !== 'string') {
        return false;
    }

    const trimmed = html.trim();

    return trimmed.includes('operations-lazy-placeholder')
        && /class="[^"]*operations-lazy-placeholder/.test(trimmed);
};

const isIraLoadingPlaceholder = (element) => (
    element instanceof HTMLElement
    && element.textContent.includes('Loading recommendations')
);

const validateSectionHtml = (sectionKey, html) => {
    if (typeof html !== 'string' || html.trim() === '') {
        throw new Error(`Missing ${sectionKey} content from operations live refresh.`);
    }

    if (!SECTIONS_ALLOW_NESTED_LAZY_MARKERS.has(sectionKey) && isLazyPlaceholderHtml(html)) {
        throw new Error(`${sectionKey} content is still loading.`);
    }
};

const isTabStillLoading = (group) => {
    const targetId = TAB_CONTENT_TARGETS[group];
    const element = targetId ? document.getElementById(targetId) : null;

    return element?.querySelector(LAZY_PLACEHOLDER_SELECTOR) !== null;
};

const findStaleLazySectionTargets = (pageRoot) => {
    const targets = new Set();

    const iraElement = document.getElementById(SECTION_TARGETS.ira_compact);

    if (isIraLoadingPlaceholder(iraElement)) {
        targets.add(SECTION_TARGETS.ira_compact);
    }

    pageRoot.querySelectorAll(LAZY_PLACEHOLDER_SELECTOR).forEach((placeholder) => {
        if (placeholder.closest('[data-operations-lazy-section]')) {
            return;
        }

        const target = placeholder.closest('[id]');

        if (target?.id) {
            targets.add(target.id);
        }
    });

    return [...targets];
};

const reconcileStaleLazySections = (pageRoot, message, retryHandler) => {
    findStaleLazySectionTargets(pageRoot).forEach((targetId) => {
        showLazyLoadError(targetId, message, retryHandler);
    });
};

const applyLiveHtml = (pageRoot, html, { validate = false } = {}) => {
    Object.entries(html ?? {}).forEach(([sectionKey, sectionHtml]) => {
        const elementId = SECTION_TARGETS[sectionKey];

        if (!elementId) {
            return;
        }

        if (validate) {
            validateSectionHtml(sectionKey, sectionHtml);
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

    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false });
};

const renderGeneratedAtMarkup = (isoString) => {
    const formattedTime = formatGeneratedAt(isoString);

    if (formattedTime === '') {
        return '';
    }

    return `<span class="operations-live-indicator" aria-hidden="true">● Live</span> Updated ${formattedTime}`;
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

const fetchLiveGroups = async (pageRoot, groups, { timeoutMs = FETCH_TIMEOUT_MS } = {}) => {
    const liveUrl = pageRoot.dataset.liveUrl;

    if (!liveUrl) {
        throw new Error('Operations live refresh URL is missing.');
    }

    const requestUrl = groups === null
        ? liveUrl
        : `${liveUrl}?groups=${groups.join(',')}`;

    const abortController = new AbortController();
    const timeoutId = window.setTimeout(() => {
        abortController.abort();
    }, timeoutMs);

    try {
        const response = await fetch(requestUrl, {
            credentials: 'same-origin',
            signal: abortController.signal,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            throw new Error(`Operations live refresh failed (${response.status}).`);
        }

        return response.json();
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') {
            throw new Error(`Operations live refresh timed out after ${Math.round(timeoutMs / 1000)}s.`);
        }

        throw error;
    } finally {
        window.clearTimeout(timeoutId);
    }
};

const activateOperationsHubTabFromQuery = (pageRoot) => {
    const hubTab = new URLSearchParams(window.location.search).get('hub_tab');
    const selectors = {
        today: '#operations-tab-today',
        team: '#operations-tab-team',
        performance: '#operations-tab-performance',
        system: '#operations-tab-system',
        automation: '#operations-tab-automation',
    };

    const selector = selectors[hubTab];

    if (!selector) {
        return;
    }

    const tabButton = pageRoot.querySelector(selector);

    if (!tabButton || !window.bootstrap?.Tab) {
        return;
    }

    window.bootstrap.Tab.getOrCreateInstance(tabButton).show();
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

const resolveAutomationHealthEmbedUrl = (pageRoot, url = null) => {
    const resolvedUrl = url ?? pageRoot.dataset.automationHealthUrl?.trim() ?? '';

    if (resolvedUrl === '') {
        throw new Error('Automation Health is not available on this page.');
    }

    return resolvedUrl;
};

const resolveAutomationPipelineEmbedUrl = (pageRoot, url = null) => {
    const resolvedUrl = url ?? pageRoot.dataset.automationPipelineUrl?.trim() ?? '';

    if (resolvedUrl === '') {
        throw new Error('Automation Pipeline is not available on this page.');
    }

    return resolvedUrl;
};

const resolveAutomationSubviewFromQuery = () => {
    const subview = new URLSearchParams(window.location.search).get('automation_view');

    return subview === 'pipeline' ? 'pipeline' : 'health';
};

const fetchAutomationEmbedHtml = async (url, embedSelector) => {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: {
            Accept: 'text/html',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!response.ok) {
        throw new Error(`Automation content failed to load (${response.status}).`);
    }

    const documentHtml = await response.text();
    const parsedDocument = new DOMParser().parseFromString(documentHtml, 'text/html');
    const embedRoot = parsedDocument.querySelector(embedSelector);

    if (!embedRoot) {
        throw new Error('Automation content is unavailable.');
    }

    return embedRoot.innerHTML;
};

const resetAutomationSubviewLoaded = (subview) => {
    const targetElement = document.getElementById(AUTOMATION_SUBVIEW_TARGETS[subview]);

    if (targetElement) {
        targetElement.dataset.operationsAutomationSubviewLoaded = 'false';
    }
};

const markAutomationSubviewLoaded = (subview) => {
    const targetElement = document.getElementById(AUTOMATION_SUBVIEW_TARGETS[subview]);

    if (targetElement) {
        targetElement.dataset.operationsAutomationSubviewLoaded = 'true';
    }
};

const bindAutomationSubnav = (pageRoot) => {
    const automationRoot = pageRoot.querySelector('[data-operations-automation-tab]');

    if (!automationRoot || automationRoot.dataset.automationSubnavBound === 'true') {
        return;
    }

    automationRoot.dataset.automationSubnavBound = 'true';

    automationRoot.querySelectorAll('[data-automation-subview-target]').forEach((button) => {
        button.addEventListener('click', () => {
            const subview = button.dataset.automationSubviewTarget;

            if (!subview) {
                return;
            }

            activateAutomationSubview(pageRoot, subview);
        });
    });
};

const activateAutomationSubview = async (pageRoot, subview, { force = false } = {}) => {
    const automationRoot = pageRoot.querySelector('[data-operations-automation-tab]');

    if (!automationRoot) {
        return false;
    }

    const normalizedSubview = subview === 'pipeline' ? 'pipeline' : 'health';

    automationRoot.querySelectorAll('[data-automation-subview-target]').forEach((button) => {
        const isActive = button.dataset.automationSubviewTarget === normalizedSubview;

        button.classList.toggle('active', isActive);
        button.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });

    automationRoot.querySelectorAll('[data-automation-subview-pane]').forEach((pane) => {
        const isActive = pane.dataset.automationSubviewPane === normalizedSubview;

        pane.classList.toggle('d-none', !isActive);
    });

    if (normalizedSubview === 'pipeline') {
        return loadAutomationPipelineSubview(pageRoot, { force });
    }

    return loadAutomationHealthSubview(pageRoot, { force });
};

const loadAutomationHealthSubview = async (pageRoot, { force = false, url = null } = {}) => {
    const targetId = AUTOMATION_SUBVIEW_TARGETS.health;
    const targetElement = document.getElementById(targetId);

    if (!targetElement) {
        return false;
    }

    if (!force && targetElement.dataset.operationsAutomationSubviewLoaded === 'true') {
        return true;
    }

    try {
        const embedUrl = resolveAutomationHealthEmbedUrl(pageRoot, url);
        const embedHtml = await fetchAutomationEmbedHtml(embedUrl, AUTOMATION_EMBED_SELECTORS.health);

        replaceSectionHtml(targetId, embedHtml);
        markAutomationSubviewLoaded('health');
        delete targetElement.dataset.automationHealthEmbedBound;
        bindAutomationHealthEmbed(targetElement, (nextUrl) => {
            loadAutomationHealthSubview(pageRoot, { force: true, url: nextUrl });
        });
        initAutomationHealth();

        return true;
    } catch (error) {
        resetAutomationSubviewLoaded('health');
        showLazyLoadError(
            targetId,
            error instanceof Error ? error.message : 'Unable to load Automation Health right now.',
            () => {
                replaceSectionHtml(targetId, renderLazySkeleton('Retrying…'));
                loadAutomationHealthSubview(pageRoot, { force: true, url });
            },
        );

        return false;
    }
};

const loadAutomationPipelineSubview = async (pageRoot, { force = false, url = null } = {}) => {
    const targetId = AUTOMATION_SUBVIEW_TARGETS.pipeline;
    const targetElement = document.getElementById(targetId);

    if (!targetElement) {
        return false;
    }

    if (!force && targetElement.dataset.operationsAutomationSubviewLoaded === 'true') {
        return true;
    }

    try {
        const embedUrl = resolveAutomationPipelineEmbedUrl(pageRoot, url);
        const embedHtml = await fetchAutomationEmbedHtml(embedUrl, AUTOMATION_EMBED_SELECTORS.pipeline);

        replaceSectionHtml(targetId, embedHtml);
        markAutomationSubviewLoaded('pipeline');

        return true;
    } catch (error) {
        resetAutomationSubviewLoaded('pipeline');
        showLazyLoadError(
            targetId,
            error instanceof Error ? error.message : 'Unable to load Automation Pipeline right now.',
            () => {
                replaceSectionHtml(targetId, renderLazySkeleton('Retrying…'));
                loadAutomationPipelineSubview(pageRoot, { force: true, url });
            },
        );

        return false;
    }
};

const loadAutomationTab = async (pageRoot, { force = false } = {}) => {
    const pane = pageRoot.querySelector('[data-operations-lazy-group="automation"]');

    if (!pane) {
        return false;
    }

    if (!force && pane.dataset.operationsLazyLoaded === 'true') {
        return true;
    }

    bindAutomationSubnav(pageRoot);

    const subview = resolveAutomationSubviewFromQuery();
    const loaded = await activateAutomationSubview(pageRoot, subview, { force });

    if (loaded) {
        markTabLoaded(pageRoot, 'automation');
    } else {
        resetTabLoaded(pageRoot, 'automation');
    }

    return loaded;
};

const loadLazyTab = async (pageRoot, group, { force = false } = {}) => {
    if (group === 'automation') {
        return loadAutomationTab(pageRoot, { force });
    }

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

        validateSectionHtml(sectionKey, sectionHtml);

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
                replaceSectionHtml(targetId, renderLazySkeleton('Retrying…'));
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

    const targetElement = document.getElementById(targetId);

    if (targetElement && !targetElement.querySelector(LAZY_PLACEHOLDER_SELECTOR)) {
        targetElement.innerHTML = renderLazySkeleton('Loading integration details…');
    }

    try {
        const payload = await fetchLiveGroups(pageRoot, [group]);
        const sectionHtml = payload.html?.[section];

        validateSectionHtml(section, sectionHtml);

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
                replaceSectionHtml(targetId, renderLazySkeleton('Retrying…'));
                loadHealthDetail(pageRoot, collapseElement, { force: true });
            },
        );

        return false;
    }
};

const captureLoadedHealthDetails = (pageRoot) => (
    [...pageRoot.querySelectorAll('[data-operations-lazy-section].show')]
        .filter((collapseElement) => collapseElement.dataset.operationsLazyLoaded === 'true')
        .map((collapseElement) => {
            const section = collapseElement.dataset.operationsLazySection;
            const targetId = HEALTH_DETAIL_TARGETS[section];

            if (!section || !targetId) {
                return null;
            }

            const target = document.getElementById(targetId);

            if (!target) {
                return null;
            }

            return {
                collapseId: collapseElement.id,
                section,
                targetId,
                html: target.innerHTML,
            };
        })
        .filter((entry) => entry !== null)
);

const restoreLoadedHealthDetails = (pageRoot, capturedDetails) => {
    capturedDetails.forEach(({ collapseId, section, targetId, html }) => {
        const collapseElement = document.getElementById(collapseId);
        const target = document.getElementById(targetId);
        const trigger = pageRoot.querySelector(`[data-bs-target="#${collapseId}"]`);

        if (!collapseElement || !target) {
            return;
        }

        collapseElement.classList.add('show');
        collapseElement.dataset.operationsLazyLoaded = 'true';

        if (trigger) {
            trigger.classList.remove('collapsed');
            trigger.setAttribute('aria-expanded', 'true');
        }

        target.innerHTML = html;
        collapseElement.dataset.operationsHealthLazyBound = 'false';
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

            validateSectionHtml('ira_full_analysis', sectionHtml);

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
                    replaceSectionHtml(modalBodyId, renderLazySkeleton('Retrying…'));
                    window.bootstrap?.Modal.getOrCreateInstance(modalElement).show();
                },
            );
        }
    });
};

const retryInitialOperationsLoad = async (pageRoot) => {
    replaceSectionHtml(TAB_CONTENT_TARGETS.today, renderLazySkeleton('Retrying…'));

    await refreshOperationsDashboard(pageRoot, { surfaceErrors: true });
    await loadLazyTab(pageRoot, 'today', { force: true });
};

const refreshOperationsDashboard = async (
    pageRoot,
    { forceFullRefresh = false, extraGroups = [], surfaceErrors = false } = {},
) => {
    const groups = buildLiveGroups(pageRoot, forceFullRefresh, extraGroups);
    const loadedHealthDetails = surfaceErrors ? [] : captureLoadedHealthDetails(pageRoot);

    try {
        const payload = await fetchLiveGroups(pageRoot, groups);

        applyLiveHtml(pageRoot, payload.html ?? {}, { validate: surfaceErrors });

        if (loadedHealthDetails.length > 0) {
            restoreLoadedHealthDetails(pageRoot, loadedHealthDetails);
        }

        bindBatchRecoveryForms(pageRoot);
        bindOperationsTabShortcuts(pageRoot);
        bindIraInsightToggleLabels(pageRoot);
        bindHealthAccordionLazyLoad(pageRoot);

        const generatedAtElement = document.getElementById('operations-dashboard-generated-at');

        if (generatedAtElement && payload.generated_at) {
            const markup = renderGeneratedAtMarkup(payload.generated_at);

            if (markup !== '') {
                generatedAtElement.innerHTML = markup;
            }
        }

        if (surfaceErrors) {
            reconcileStaleLazySections(
                pageRoot,
                'Unable to refresh this section right now.',
                () => {
                    retryInitialOperationsLoad(pageRoot);
                },
            );
        }
    } catch (error) {
        if (surfaceErrors) {
            reconcileStaleLazySections(
                pageRoot,
                error instanceof Error ? error.message : 'Unable to refresh operations dashboard right now.',
                () => {
                    retryInitialOperationsLoad(pageRoot);
                },
            );

            return;
        }

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
            } finally {
                if (button instanceof HTMLButtonElement) {
                    button.disabled = false;
                }
            }
        });
    });
};

const guardAgainstStaleLazyPlaceholders = (pageRoot) => {
    window.setTimeout(() => {
        pageRoot.querySelectorAll(LAZY_PLACEHOLDER_SELECTOR).forEach((placeholder) => {
            if (placeholder.closest('[data-operations-lazy-section]:not(.show)')) {
                return;
            }

            const automationTabPane = placeholder.closest('[data-operations-automation-tab]');

            if (automationTabPane) {
                const operationsAutomationPane = pageRoot.querySelector('#operations-pane-automation');

                if (!operationsAutomationPane?.classList.contains('active')) {
                    return;
                }

                const pipelineSubview = placeholder.closest('[data-automation-subview-pane="pipeline"]');

                if (pipelineSubview?.classList.contains('d-none')) {
                    return;
                }
            }

            const target = placeholder.closest('[id]');

            if (!target?.id || target.querySelector('.operations-lazy-error')) {
                return;
            }

            const healthCollapse = placeholder.closest('[data-operations-lazy-section]');
            const retryHandler = healthCollapse
                ? () => {
                    replaceSectionHtml(target.id, renderLazySkeleton('Retrying…'));
                    loadHealthDetail(pageRoot, healthCollapse, { force: true });
                }
                : () => {
                    const group = Object.entries(TAB_CONTENT_TARGETS)
                        .find(([, targetId]) => targetId === target.id)?.[0];

                    if (!group) {
                        retryInitialOperationsLoad(pageRoot);

                        return;
                    }

                    replaceSectionHtml(target.id, renderLazySkeleton('Retrying…'));
                    loadLazyTab(pageRoot, group, { force: true });
                };

            showLazyLoadError(
                target.id,
                'This section took too long to load.',
                retryHandler,
            );
        });
    }, LAZY_LOAD_GUARD_MS);
};

const bindStickyOperationsTabs = (pageRoot) => {
    const sentinel = document.getElementById('operations-tabs-sentinel');
    const tabsCard = pageRoot.querySelector('.operations-dashboard-tabs');

    if (!sentinel || !tabsCard || tabsCard.dataset.operationsStickyBound === 'true') {
        return;
    }

    tabsCard.dataset.operationsStickyBound = 'true';

    const topbarHeight = getComputedStyle(document.documentElement)
        .getPropertyValue('--topbar-height')
        .trim() || '52px';

    const observer = new IntersectionObserver(([entry]) => {
        tabsCard.classList.toggle('is-sticky', !entry.isIntersecting);
    }, {
        threshold: 0,
        rootMargin: `-${topbarHeight} 0px 0px 0px`,
    });

    observer.observe(sentinel);
};

const isOperationsSsrFresh = (pageRoot, maxAgeMs = SSR_FRESHNESS_MS) => {
    const generatedAt = pageRoot.dataset.generatedAt?.trim() ?? '';

    if (generatedAt === '') {
        return false;
    }

    const timestamp = Date.parse(generatedAt);

    if (Number.isNaN(timestamp)) {
        return false;
    }

    return Date.now() - timestamp < maxAgeMs;
};

const initOperationsDashboard = async () => {
    const pageRoot = document.getElementById('operations-dashboard-root');

    if (!pageRoot) {
        return;
    }

    console.info('Operations dashboard JS version P06-07-021 loaded');

    bindBatchRecoveryForms(pageRoot);
    bindOperationsTabShortcuts(pageRoot);
    activateOperationsHubTabFromQuery(pageRoot);
    bindIraInsightToggleLabels(pageRoot);
    bindIraFullAnalysisModal(pageRoot);
    bindStickyOperationsTabs(pageRoot);

    if (!isOperationsSsrFresh(pageRoot)) {
        await refreshOperationsDashboard(pageRoot, { surfaceErrors: true });
    }

    const hubTab = new URLSearchParams(window.location.search).get('hub_tab');

    if (hubTab === 'automation' && pageRoot.dataset.automationHealthUrl) {
        await loadAutomationTab(pageRoot, { force: true });
    } else if (isTabStillLoading('today')) {
        await loadLazyTab(pageRoot, 'today', { force: true });
    }

    const intervalMs = Number(pageRoot.dataset.liveInterval ?? 30000);
    const fullRefreshIntervalMs = Number(pageRoot.dataset.liveFullInterval ?? 120000);
    startPolling(pageRoot, intervalMs, fullRefreshIntervalMs);
    guardAgainstStaleLazyPlaceholders(pageRoot);
};

export {
    captureLoadedHealthDetails,
    fetchLiveGroups,
    findStaleLazySectionTargets,
    guardAgainstStaleLazyPlaceholders,
    initOperationsDashboard,
    isOperationsSsrFresh,
    isLazyPlaceholderHtml,
    loadHealthDetail,
    loadAutomationHealthSubview,
    loadAutomationPipelineSubview,
    loadAutomationTab,
    loadLazyTab,
    refreshOperationsDashboard,
    renderLazySkeleton,
    restoreLoadedHealthDetails,
    showLazyLoadError,
    validateSectionHtml,
};
