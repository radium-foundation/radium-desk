import { getWorkspaceSession } from './workspace/session';
import { rememberLastCustomer } from './agent-dashboard';
import { initUnifiedTimeline } from './unified-timeline';
import { initCustomer360Cockpit, bindIraDisclosures } from './customer-360-cockpit';
import { bindBonvoiceClickToCall } from './bonvoice-click-to-call';
import { initMoreMenu, closeMenu as closeMoreMenu, isMoreMenuOpen, openMoreMenuForHost } from './customer-360-more-menu';

const SESSION_REASON = 'customer-360-drawer';

let customer360RefreshAbortController = null;

const logCustomer360Failure = (endpoint, status, context, error = null) => {
    if (!import.meta.env?.DEV) {
        return;
    }

    console.error('[Customer 360] Request failed', {
        context,
        endpoint,
        status,
        error,
    });
};

const drawerContentUrl = (baseUrl, incidentId) => `${baseUrl}/${incidentId}/customer-360`;

const verifyAiDomIntegrity = (contentHost) => {
    if (!import.meta.env?.DEV) {
        return;
    }

    const aiTab = contentHost.querySelector('#customer-360-tab-ai-assistant');
    const workbench = contentHost.querySelector('#customer-360-ai-workbench');

    if (!aiTab || !workbench || !aiTab.contains(workbench)) {
        console.error('Customer360: IRA AI DOM structure invalid');
    }
};

const hashContent = async (content) => {
    if (!content || !globalThis.crypto?.subtle) {
        return null;
    }

    const digest = await globalThis.crypto.subtle.digest(
        'SHA-256',
        new TextEncoder().encode(content),
    );

    return Array.from(new Uint8Array(digest))
        .map((byte) => byte.toString(16).padStart(2, '0'))
        .join('');
};

const copyTextToClipboard = async (text) => {
    await navigator.clipboard.writeText(text);
};

const COPY_SUCCESS_MS = 1500;
const DEVICE_SYNC_POLL_MS = 10000;
const TIMELINE_POLL_MS = 30000;

const showInlineCopySuccess = (button) => {
    const icon = button.querySelector('[data-customer-360-copy-icon]');
    const check = button.querySelector('[data-customer-360-copy-check]');
    const label = button.dataset.copyLabel ?? 'Value';

    if (!icon || !check) {
        return;
    }

    if (button.dataset.copyResetTimer) {
        clearTimeout(Number(button.dataset.copyResetTimer));
    }

    icon.hidden = true;
    check.hidden = false;
    button.classList.add('is-copied');
    button.setAttribute('aria-label', `${label} copied`);

    const timerId = setTimeout(() => {
        icon.hidden = false;
        check.hidden = true;
        button.classList.remove('is-copied');
        button.setAttribute('aria-label', `Copy ${label}`);
        delete button.dataset.copyResetTimer;
    }, COPY_SUCCESS_MS);

    button.dataset.copyResetTimer = String(timerId);
};

const isInteractiveTarget = (target) => {
    if (!(target instanceof Element)) {
        return false;
    }

    return Boolean(
        target.closest([
            'button',
            'input',
            'textarea',
            'select',
            'label',
            '[data-workspace-trigger]',
            '[data-inline-transaction]',
            '[data-inline-serial]',
            '[data-copyable-identifier]',
            '.copyable-identifier',
            '.dashboard-select-cell',
            '[data-dashboard-activity-stream-toggle]',
            '[data-activity-thread-toggle]',
        ].join(', '))
    );
};

const resolveCustomer360Trigger = (target, pageRoot) => {
    if (!(target instanceof Element)) {
        return null;
    }

    const activityEntry = target.closest('[data-dashboard-activity-entry][data-incident-id]');

    if (activityEntry && pageRoot.contains(activityEntry)) {
        return {
            incidentId: activityEntry.getAttribute('data-incident-id'),
            referenceLabel: activityEntry.getAttribute('data-customer-360-label')
                ?? activityEntry.querySelector('.dashboard-activity-entry-incident-label')?.textContent?.trim()
                ?? '',
        };
    }

    const row = target.closest('tr[data-incident-id]');

    if (row && pageRoot.contains(row)) {
        return {
            incidentId: row.dataset.incidentId,
            referenceLabel: row.querySelector('.case-reference-link')?.textContent?.trim() ?? '',
        };
    }

    return null;
};

export const initCustomer360Drawer = ({ pageRoot, showToast, initTooltips } = {}) => {
    const root = pageRoot ?? document.getElementById('dashboard-page');

    if (!root) {
        return null;
    }

    const drawer = document.querySelector('[data-customer-360-drawer]');

    if (!drawer) {
        return null;
    }

    const backdrop = drawer.querySelector('[data-customer-360-backdrop]');
    const panel = drawer.querySelector('[data-customer-360-panel]');
    const closeButton = drawer.querySelector('[data-customer-360-close]');
    const contentHost = drawer.querySelector('[data-customer-360-content-host]');
    const loadingState = drawer.querySelector('[data-customer-360-loading]');
    const errorState = drawer.querySelector('[data-customer-360-error]');
    const subtitle = drawer.querySelector('[data-customer-360-subtitle]');
    const baseUrl = root.getAttribute('data-customer-360-url');

    if (!baseUrl || !contentHost) {
        return null;
    }

    let activeIncidentId = null;
    let fetchController = null;
    let pendingOpenOptions = {};
    let previouslyFocusedElement = null;
    let devicePollTimer = null;
    let timelinePollTimer = null;
    let cockpitApi = null;
    const lazyTabState = {
        executiveSummary: false,
        timeline: false,
        ai: false,
    };

    const stopTimelinePolling = () => {
        if (timelinePollTimer === null) {
            return;
        }

        clearInterval(timelinePollTimer);
        timelinePollTimer = null;
    };

    const stopDeviceSyncPolling = () => {
        if (devicePollTimer === null) {
            return;
        }

        clearInterval(devicePollTimer);
        devicePollTimer = null;
    };

    const bindDeviceSectionInteractions = () => {
        bindCopyActions();
        bindRadiumBoxSyncActions();
        initTooltips?.(contentHost);
    };

    const replaceDeviceSection = (deviceHtml) => {
        const section = contentHost.querySelector('[data-customer-360-device-section]');

        if (!section || !deviceHtml) {
            return false;
        }

        section.outerHTML = deviceHtml;
        bindDeviceSectionInteractions();
        configureDeviceSyncPolling();

        return true;
    };

    const refreshDeviceSection = async (refreshUrl) => {
        if (!refreshUrl) {
            return;
        }

        try {
            const response = await fetch(refreshUrl, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                logCustomer360Failure(refreshUrl, response.status, 'device-refresh');

                return;
            }

            const payload = await response.json();

            if (payload.html) {
                replaceDeviceSection(payload.html);
            }
        } catch (error) {
            logCustomer360Failure(refreshUrl, null, 'device-refresh', error);
        }
    };

    const configureDeviceSyncPolling = () => {
        stopDeviceSyncPolling();

        const section = contentHost.querySelector('[data-customer-360-device-section]');

        if (!section || section.dataset.shouldPollSync !== 'true') {
            return;
        }

        const refreshUrl = section.dataset.deviceRefreshUrl?.trim() ?? '';

        if (refreshUrl === '') {
            return;
        }

        devicePollTimer = setInterval(() => {
            refreshDeviceSection(refreshUrl);
        }, DEVICE_SYNC_POLL_MS);
    };

    const refreshTimelineSection = async (refreshUrl) => {
        if (!refreshUrl) {
            return;
        }

        try {
            const response = await fetch(`${refreshUrl}?offset=0`, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                logCustomer360Failure(refreshUrl, response.status, 'timeline-refresh');

                return;
            }

            const payload = await response.json();
            const section = contentHost.querySelector('[data-customer-360-timeline-section]');

            if (section && payload.html) {
                section.outerHTML = payload.html;
                initUnifiedTimeline(contentHost);
            }
        } catch (error) {
            logCustomer360Failure(refreshUrl, null, 'timeline-refresh', error);
        }
    };

    const configureTimelinePolling = () => {
        stopTimelinePolling();

        const section = contentHost.querySelector('[data-customer-360-timeline-section]');
        const refreshUrl = section?.dataset.timelineRefreshUrl?.trim() ?? '';

        if (refreshUrl === '') {
            return;
        }

        timelinePollTimer = setInterval(() => {
            refreshTimelineSection(refreshUrl);
        }, TIMELINE_POLL_MS);
    };

    const setLoading = (isLoading) => {
        loadingState.hidden = !isLoading;
    };

    const setError = (message = '') => {
        if (!errorState) {
            return;
        }

        if (message === '') {
            errorState.classList.add('d-none');
            errorState.textContent = '';

            return;
        }

        errorState.classList.remove('d-none');
        errorState.textContent = message;
    };

    const clearContent = () => {
        contentHost.innerHTML = '';
    };

    const bindCopyActions = () => {
        contentHost.querySelectorAll('[data-customer-360-copy]').forEach((button) => {
            button.addEventListener('click', async () => {
                const value = button.dataset.copyValue?.trim() ?? '';

                if (value === '') {
                    return;
                }

                await copyTextToClipboard(value);

                const label = button.dataset.copyLabel
                    ?? ({
                        mobile: 'Mobile number',
                        phone: 'Customer Phone',
                        email: 'Customer Email',
                        serial: 'Serial Number',
                        'order-id': 'Order ID',
                    })[button.dataset.customer360Copy]
                    ?? 'Value';

                showInlineCopySuccess(button);
                showToast?.(`${label} copied`);
            });
        });
    };

    const postWorkbenchAudit = async (root, payload) => {
        const auditUrl = root.dataset.aiWorkbenchAuditUrl;

        if (!auditUrl) {
            return;
        }

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

        try {
            await fetch(auditUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
                },
                body: JSON.stringify(payload),
            });
        } catch {
            // Audit failures should not block operator actions.
        }
    };

    const bindWorkbenchActions = () => {
        const workbenchRoot = contentHost.querySelector('[data-ai-workbench-root]');

        if (!workbenchRoot) {
            return;
        }

        let viewedLogged = false;

        const logViewed = async () => {
            if (viewedLogged) {
                return;
            }

            viewedLogged = true;
            await postWorkbenchAudit(workbenchRoot, {
                action: 'viewed',
                artifact_key: 'workbench',
            });
        };

        workbenchRoot.querySelectorAll('[data-ai-workbench-reply-tab]').forEach((tab) => {
            tab.addEventListener('click', () => {
                const tabKey = tab.dataset.aiWorkbenchReplyTab;

                workbenchRoot.querySelectorAll('[data-ai-workbench-reply-tab]').forEach((button) => {
                    const isActive = button.dataset.aiWorkbenchReplyTab === tabKey;
                    button.classList.toggle('is-active', isActive);
                    button.setAttribute('aria-selected', isActive ? 'true' : 'false');
                });

                workbenchRoot.querySelectorAll('[data-ai-workbench-reply-pane]').forEach((pane) => {
                    pane.classList.toggle('d-none', pane.dataset.aiWorkbenchReplyPane !== tabKey);
                });
            });
        });

        workbenchRoot.querySelectorAll('[data-ai-workbench-copy]').forEach((button) => {
            button.addEventListener('click', async () => {
                const artifactKey = button.dataset.artifactKey ?? 'unknown';
                const editor = workbenchRoot.querySelector(`[data-ai-workbench-editor="${artifactKey}"]`);
                const value = editor instanceof HTMLTextAreaElement
                    ? editor.value.trim()
                    : button.dataset.copyValue?.trim() ?? '';

                if (value === '') {
                    return;
                }

                await copyTextToClipboard(value);
                showToast?.('IRA AI suggestion copied');

                await postWorkbenchAudit(workbenchRoot, {
                    action: 'copied',
                    artifact_key: artifactKey,
                    content_length: value.length,
                    content_hash: await hashContent(value),
                });
            });
        });

        workbenchRoot.querySelectorAll('[data-ai-workbench-insert]').forEach((button) => {
            button.addEventListener('click', async () => {
                const artifactKey = button.dataset.artifactKey ?? 'unknown';
                const target = button.dataset.insertTarget ?? 'editor';
                const workbenchEditor = workbenchRoot.querySelector(`[data-ai-workbench-editor="${artifactKey}"]`);
                const remarkEditor = document.querySelector('#modal_note_body');
                const editor = target === 'remark' && remarkEditor instanceof HTMLTextAreaElement
                    ? remarkEditor
                    : workbenchEditor;
                const value = workbenchEditor instanceof HTMLTextAreaElement
                    ? workbenchEditor.value.trim()
                    : button.dataset.insertValue?.trim() ?? '';

                if (value === '') {
                    return;
                }

                if (editor instanceof HTMLTextAreaElement) {
                    editor.readOnly = false;
                    editor.value = value;
                    editor.focus();
                }

                document.dispatchEvent(new CustomEvent('workbench:insert', {
                    detail: {
                        incidentId: workbenchRoot.dataset.aiWorkbenchIncidentId,
                        target,
                        content: value,
                        artifactKey,
                    },
                }));

                showToast?.('IRA AI suggestion inserted into editor');

                await postWorkbenchAudit(workbenchRoot, {
                    action: 'inserted',
                    artifact_key: artifactKey,
                    target,
                    content_length: value.length,
                    content_hash: await hashContent(value),
                });
            });
        });

        const showWorkbenchError = (message) => {
            let errorNode = workbenchRoot.querySelector('[data-ai-workbench-error]');

            if (!errorNode) {
                errorNode = document.createElement('div');
                errorNode.className = 'alert alert-danger py-2 px-3 mb-2';
                errorNode.setAttribute('data-ai-workbench-error', '');
                errorNode.setAttribute('role', 'alert');
                workbenchRoot.prepend(errorNode);
            }

            errorNode.textContent = message;
            errorNode.hidden = false;
        };

        const clearWorkbenchError = () => {
            const errorNode = workbenchRoot.querySelector('[data-ai-workbench-error]');

            if (!errorNode) {
                return;
            }

            errorNode.textContent = '';
            errorNode.hidden = true;
        };

        const refreshWorkbench = async (artifactKey = 'workbench') => {
            const refreshUrl = workbenchRoot.dataset.aiWorkbenchRefreshUrl;

            if (!refreshUrl) {
                return;
            }

            const scopedRefreshUrl = refreshUrl.includes('?')
                ? `${refreshUrl}&scope=workbench`
                : `${refreshUrl}?scope=workbench`;

            try {
                const response = await fetch(scopedRefreshUrl, {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) {
                    logCustomer360Failure(refreshUrl, response.status, 'workbench-refresh');
                    showWorkbenchError('Unable to refresh IRA AI suggestions. Please try again.');
                    showToast?.('IRA AI unavailable — unable to refresh suggestions');

                    return;
                }

                const payload = await response.json();
                const container = contentHost.querySelector('#customer-360-ai-workbench');

                if (container && payload.html) {
                    container.outerHTML = payload.html;
                    bindWorkbenchActions();
                }

                clearWorkbenchError();
                showToast?.('IRA AI refreshed');

                const refreshedWorkbenchRoot = contentHost.querySelector('[data-ai-workbench-root]');

                if (refreshedWorkbenchRoot) {
                    await postWorkbenchAudit(refreshedWorkbenchRoot, {
                        action: 'viewed',
                        artifact_key: artifactKey,
                    });
                }
            } catch (error) {
                logCustomer360Failure(refreshUrl, null, 'workbench-refresh', error);
                showWorkbenchError('Unable to refresh IRA AI suggestions. Please try again.');
                showToast?.('IRA AI unavailable — unable to refresh suggestions');
            }
        };

        workbenchRoot.querySelectorAll('[data-ai-workbench-refresh]').forEach((button) => {
            button.addEventListener('click', async () => {
                await refreshWorkbench(button.dataset.artifactKey ?? 'workbench');
            });
        });

        logViewed();
    };

    const ACTIVE_TAB_ATTR = 'data-customer-360-active-tab';

    const resetLazyTabState = () => {
        lazyTabState.executiveSummary = false;
        lazyTabState.timeline = false;
        lazyTabState.ai = false;
    };

    const loadExecutiveSummary = async () => {
        if (lazyTabState.executiveSummary) {
            return;
        }

        const placeholder = contentHost.querySelector('[data-customer-360-executive-summary-lazy]');
        const loadUrl = placeholder?.dataset.executiveSummaryUrl?.trim() ?? '';

        if (!placeholder || loadUrl === '') {
            return;
        }

        lazyTabState.executiveSummary = true;

        try {
            const response = await fetch(loadUrl, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                logCustomer360Failure(loadUrl, response.status, 'executive-summary-load');
                placeholder.innerHTML = '<p class="text-muted small mb-0">Unable to load executive summary.</p>';

                return;
            }

            const payload = await response.json();

            if (payload.html) {
                placeholder.outerHTML = payload.html;
                bindExecutiveSummaryTranslation();
                bindIraDisclosures(contentHost);
                initTooltips?.(contentHost);
            }
        } catch (error) {
            logCustomer360Failure(loadUrl, null, 'executive-summary-load', error);
            placeholder.innerHTML = '<p class="text-muted small mb-0">Unable to load executive summary.</p>';
        }
    };

    const loadTimelineTab = async () => {
        if (lazyTabState.timeline) {
            return;
        }

        const placeholder = contentHost.querySelector('[data-customer-360-timeline-tab]');
        const loadUrl = placeholder?.dataset.timelineTabUrl?.trim() ?? '';

        if (!placeholder || loadUrl === '') {
            return;
        }

        lazyTabState.timeline = true;

        try {
            const response = await fetch(loadUrl, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                logCustomer360Failure(loadUrl, response.status, 'timeline-tab-load');
                placeholder.innerHTML = '<p class="text-muted small mb-0">Unable to load timeline.</p>';

                return;
            }

            const payload = await response.json();

            if (payload.html) {
                placeholder.outerHTML = payload.html;
                initUnifiedTimeline(contentHost);
                bindIraDisclosures(contentHost);
                configureTimelinePolling();
            }
        } catch (error) {
            logCustomer360Failure(loadUrl, null, 'timeline-tab-load', error);
            placeholder.innerHTML = '<p class="text-muted small mb-0">Unable to load timeline.</p>';
        }
    };

    const loadAiTab = async () => {
        if (lazyTabState.ai) {
            return;
        }

        const placeholder = contentHost.querySelector('[data-customer-360-ai-tab]');
        const loadUrl = placeholder?.dataset.aiTabUrl?.trim() ?? '';

        if (!placeholder || loadUrl === '') {
            return;
        }

        lazyTabState.ai = true;

        try {
            const response = await fetch(loadUrl, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                logCustomer360Failure(loadUrl, response.status, 'ai-tab-load');
                placeholder.innerHTML = '<p class="text-muted small mb-0">Unable to load IRA AI.</p>';

                return;
            }

            const payload = await response.json();

            if (payload.html) {
                placeholder.outerHTML = payload.html;
                verifyAiDomIntegrity(contentHost);
                bindWorkbenchActions();
            }
        } catch (error) {
            logCustomer360Failure(loadUrl, null, 'ai-tab-load', error);
            placeholder.innerHTML = '<p class="text-muted small mb-0">Unable to load IRA AI.</p>';
        }
    };

    const hydrateLazySectionsForTab = (tabKey) => {
        if (tabKey === 'overview') {
            loadExecutiveSummary();
        }

        if (tabKey === 'timeline') {
            loadTimelineTab();
        }

        if (tabKey === 'ai-assistant') {
            loadAiTab();
        }
    };

    const getPersistedTab = () => contentHost.getAttribute(ACTIVE_TAB_ATTR);

    const setPersistedTab = (tabKey) => {
        contentHost.setAttribute(ACTIVE_TAB_ATTR, tabKey);
    };

    const clearPersistedTab = () => {
        contentHost.removeAttribute(ACTIVE_TAB_ATTR);
    };

    const getTabRoot = () => contentHost.querySelector('[data-customer-360-content]') ?? contentHost;

    const activateTab = (tabKey) => {
        const tabRoot = getTabRoot();
        const tabs = tabRoot.querySelectorAll('[data-customer-360-tab]');
        const panes = tabRoot.querySelectorAll('[data-customer-360-tab-pane]');

        if (tabs.length === 0 || panes.length === 0) {
            return;
        }

        tabs.forEach((tab) => {
            const tabKeyForNode = tab.getAttribute('data-customer-360-tab');
            const isActive = tabKeyForNode === tabKey;
            tab.classList.toggle('active', isActive);
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        panes.forEach((pane) => {
            const paneKey = pane.getAttribute('data-customer-360-tab-pane');
            const isActive = paneKey === tabKey;
            pane.classList.toggle('d-none', !isActive);
        });

        setPersistedTab(tabKey);

        const drawerBody = drawer.querySelector('[data-customer-360-body]');

        if (drawerBody) {
            drawerBody.scrollTop = 0;
        }

        closeMoreMenu();

        hydrateLazySectionsForTab(tabKey);
    };

    const syncTabState = () => {
        const tabs = getTabRoot().querySelectorAll('[data-customer-360-tab]');

        if (tabs.length === 0) {
            return;
        }

        const persistedTab = getPersistedTab();
        const serverActiveTab = Array.from(tabs).find((tab) => tab.classList.contains('active'))
            ?.getAttribute('data-customer-360-tab');
        const initialTab = persistedTab ?? serverActiveTab ?? 'overview';

        activateTab(initialTab);
    };

    const bindTabNavigation = () => {
        if (contentHost.dataset.customer360TabsBound === 'true') {
            return;
        }

        contentHost.dataset.customer360TabsBound = 'true';

        contentHost.addEventListener('click', (event) => {
            const tab = event.target.closest('[data-customer-360-tab]');

            if (!tab || !getTabRoot().contains(tab)) {
                return;
            }

            event.preventDefault();
            const tabKey = tab.getAttribute('data-customer-360-tab');

            if (!tabKey) {
                return;
            }

            activateTab(tabKey);
        });
    };

    const bindCockpitInteractions = () => {
        initMoreMenu(contentHost);
    };

    const bindExecutiveSummaryTranslation = () => {
        const summaryRoot = contentHost.querySelector('[data-ira-executive-summary]');

        if (!summaryRoot) {
            return;
        }

        const toggleButton = summaryRoot.querySelector('[data-ira-summary-lang-toggle]');
        const contentRoot = summaryRoot.querySelector('[data-ira-summary-content]');

        if (!toggleButton || !contentRoot) {
            return;
        }

        let hindiPayload = null;
        let showingHindi = false;
        let loadingTranslation = false;

        const parseEnglishPayload = () => {
            const raw = contentRoot.dataset.iraSummaryEn ?? '{}';

            try {
                return JSON.parse(raw);
            } catch {
                return null;
            }
        };

        const renderPayload = (payload) => {
            const executiveBlock = contentRoot.querySelector('[data-ira-summary-block="executive"]');
            const opinionBlock = contentRoot.querySelector('[data-ira-summary-block="opinion"]');
            const recommendationBlock = contentRoot.querySelector('[data-ira-summary-block="recommendation"]');

            if (executiveBlock) {
                executiveBlock.innerHTML = '';
                (payload.executive_summary ?? []).forEach((line) => {
                    if (typeof line === 'string' && line.startsWith('Customer journey:')) {
                        return;
                    }

                    const item = document.createElement('li');
                    item.textContent = line;
                    executiveBlock.appendChild(item);
                });
            }

            if (opinionBlock) {
                opinionBlock.textContent = payload.opinion ?? '';
            }

            if (recommendationBlock) {
                recommendationBlock.textContent = payload.recommendation ?? '';
            }
        };

        const setLanguageState = (isHindi) => {
            showingHindi = isHindi;
            toggleButton.setAttribute('aria-pressed', isHindi ? 'true' : 'false');
            toggleButton.classList.toggle('is-active', isHindi);
        };

        toggleButton.addEventListener('click', async () => {
            if (showingHindi) {
                const englishPayload = parseEnglishPayload();

                if (englishPayload) {
                    renderPayload(englishPayload);
                }

                setLanguageState(false);

                return;
            }

            if (hindiPayload) {
                renderPayload(hindiPayload);
                setLanguageState(true);

                return;
            }

            const englishPayload = parseEnglishPayload();
            const translateUrl = summaryRoot.dataset.iraTranslateUrl;

            if (!englishPayload || !translateUrl || loadingTranslation) {
                return;
            }

            loadingTranslation = true;
            toggleButton.disabled = true;

            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

            try {
                const response = await fetch(translateUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
                    },
                    body: JSON.stringify(englishPayload),
                });

                if (!response.ok) {
                    showToast?.('Unable to load Hindi translation. Please try again.');

                    return;
                }

                hindiPayload = await response.json();
                renderPayload(hindiPayload);
                setLanguageState(true);
            } catch {
                showToast?.('Unable to load Hindi translation. Please try again.');
            } finally {
                loadingTranslation = false;
                toggleButton.disabled = false;
            }
        });
    };

    const bindRadiumBoxSyncActions = () => {
        contentHost.querySelectorAll('[data-customer-360-radiumbox-sync]').forEach((button) => {
            button.addEventListener('click', async () => {
                const syncUrl = button.dataset.syncUrl?.trim() ?? '';

                if (syncUrl === '' || button.disabled) {
                    return;
                }

                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                const previousHtml = contentHost.innerHTML;

                button.disabled = true;
                button.classList.add('is-syncing');

                try {
                    const response = await fetch(syncUrl, {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
                        },
                    });

                    const payload = await response.json().catch(() => ({}));

                    if (!response.ok || !payload.success) {
                        const message = payload.message ?? 'Unable to synchronize serial number. Please try again.';
                        showToast?.(message, 'danger');

                        return;
                    }

                    if (payload.device_html) {
                        replaceDeviceSection(payload.device_html);
                        const timelineSection = contentHost.querySelector('[data-customer-360-timeline-section]');

                        if (timelineSection?.dataset.timelineRefreshUrl) {
                            await refreshTimelineSection(timelineSection.dataset.timelineRefreshUrl);
                        }
                    } else if (payload.html) {
                        contentHost.innerHTML = payload.html;
                        finalizeDrawerContent();
                    } else if (activeIncidentId !== null) {
                        await refreshDeviceSection(
                            contentHost.querySelector('[data-customer-360-device-section]')?.dataset.deviceRefreshUrl,
                        );
                    }

                    showToast?.(payload.message ?? '✓ Device information synchronized successfully.');
                } catch (error) {
                    logCustomer360Failure(syncUrl, null, 'radiumbox-sync', error);
                    contentHost.innerHTML = previousHtml;
                    finalizeDrawerContent();
                    showToast?.('Unable to synchronize serial number. Please try again.', 'danger');
                } finally {
                    const activeButton = contentHost.querySelector('[data-customer-360-radiumbox-sync]');

                    if (activeButton instanceof HTMLButtonElement) {
                        activeButton.disabled = false;
                        activeButton.classList.remove('is-syncing');
                    }
                }
            });
        });
    };

    const finalizeDrawerContent = () => {
        try {
            bindCockpitInteractions();
            bindDeviceSectionInteractions();
            syncTabState();
            configureDeviceSyncPolling();
            hydrateLazySectionsForTab(getPersistedTab() ?? 'overview');
            bindCockpitChrome();

            const anchor = pendingOpenOptions.anchor;

            if (anchor) {
                const drawerBody = drawer.querySelector('[data-customer-360-body]');
                const target = contentHost.querySelector(`#${CSS.escape(anchor)}`);

                if (target && drawerBody) {
                    requestAnimationFrame(() => {
                        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    });
                }
            }

            pendingOpenOptions = {};
            if (options.openMoreMenu) {
                requestAnimationFrame(() => {
                    openMoreMenuForHost(contentHost);
                });
            }
        } catch (error) {
            logCustomer360Failure(null, null, 'drawer-init', error);
        }
    };

    const bindCockpitChrome = () => {
        cockpitApi?.destroy?.();
        cockpitApi = initCustomer360Cockpit({
            drawer,
            contentHost,
            activateTab,
            showToast,
            isOpen: () => drawer.classList.contains('is-open'),
        });
        bindBonvoiceClickToCall(contentHost, { showToast });
    };

    const loadInitialContent = async (incidentId) => {
        fetchController?.abort();
        fetchController = new AbortController();

        setError('');
        closeMoreMenu();
        clearContent();
        setLoading(true);

        const url = drawerContentUrl(baseUrl, incidentId);

        try {
            const response = await fetch(url, {
                method: 'GET',
                headers: {
                    Accept: 'text/html',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: fetchController.signal,
            });

            if (!response.ok) {
                logCustomer360Failure(url, response.status, 'initial-load');
                setError('Unable to load customer details. Please try again.');

                return;
            }

            const html = await response.text();
            contentHost.innerHTML = html;
            finalizeDrawerContent();
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }

            logCustomer360Failure(url, null, 'initial-load', error);
            setError('Unable to load customer details. Please try again.');
        } finally {
            setLoading(false);
        }
    };

    const refreshDrawerContent = async (incidentId) => {
        fetchController?.abort();
        fetchController = new AbortController();

        const url = drawerContentUrl(baseUrl, incidentId);
        const previousHtml = contentHost.innerHTML;

        try {
            const response = await fetch(url, {
                method: 'GET',
                headers: {
                    Accept: 'text/html',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: fetchController.signal,
            });

            if (!response.ok) {
                logCustomer360Failure(url, response.status, 'drawer-refresh');
                showToast?.('Unable to refresh customer details. Please try again.');

                return;
            }

            const html = await response.text();
            contentHost.innerHTML = html;
            finalizeDrawerContent();
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }

            logCustomer360Failure(url, null, 'drawer-refresh', error);
            contentHost.innerHTML = previousHtml;
            showToast?.('Unable to refresh customer details. Please try again.');
        }
    };

    const close = () => {
        fetchController?.abort();
        fetchController = null;
        stopDeviceSyncPolling();

        if (!drawer.classList.contains('is-open') && activeIncidentId === null) {
            return;
        }

        activeIncidentId = null;

        drawer.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('customer-360-drawer-open');

        getWorkspaceSession().release(SESSION_REASON);

        closeMoreMenu();

        cockpitApi?.destroy?.();
        cockpitApi = null;

        clearContent();
        clearPersistedTab();
        resetLazyTabState();
        setError('');
        setLoading(false);

        if (subtitle) {
            subtitle.textContent = '';
        }

        if (previouslyFocusedElement instanceof HTMLElement) {
            previouslyFocusedElement.focus();
        }

        previouslyFocusedElement = null;
    };

    const open = async (incidentId, referenceLabel = '', options = {}) => {
        if (activeIncidentId === incidentId && drawer.classList.contains('is-open')) {
            if (options.openMoreMenu) {
                openMoreMenuForHost(contentHost);
            }

            return;
        }

        const previousIncidentId = activeIncidentId;

        previouslyFocusedElement = document.activeElement instanceof HTMLElement
            ? document.activeElement
            : null;

        activeIncidentId = incidentId;
        rememberLastCustomer(incidentId, referenceLabel);
        pendingOpenOptions = {
            tab: options.tab ?? null,
            anchor: options.anchor ?? null,
            openMoreMenu: options.openMoreMenu ?? false,
        };

        if (String(previousIncidentId) !== String(incidentId)) {
            clearPersistedTab();
            resetLazyTabState();
        }

        if (pendingOpenOptions.tab) {
            setPersistedTab(pendingOpenOptions.tab);
        }
        drawer.classList.add('is-open');
        drawer.setAttribute('aria-hidden', 'false');
        document.body.classList.add('customer-360-drawer-open');

        if (subtitle) {
            subtitle.textContent = referenceLabel;
        }

        getWorkspaceSession().acquire(SESSION_REASON, {
            incidentId: Number(incidentId),
        });

        closeButton?.focus();
        await loadInitialContent(incidentId);
    };

    const handleCustomer360Pointer = (event) => {
        if (isInteractiveTarget(event.target)) {
            return;
        }

        const trigger = resolveCustomer360Trigger(event.target, root);

        if (!trigger?.incidentId) {
            return;
        }

        event.preventDefault();
        open(trigger.incidentId, trigger.referenceLabel);
    };

    const handleCustomer360Keydown = (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }

        const entry = event.target.closest('[data-dashboard-activity-entry][data-incident-id]');

        if (!entry || !root.contains(entry) || event.target !== entry) {
            return;
        }

        event.preventDefault();
        open(
            entry.getAttribute('data-incident-id'),
            entry.getAttribute('data-customer-360-label') ?? '',
        );
    };

    bindTabNavigation();

    root.addEventListener('click', handleCustomer360Pointer);
    root.addEventListener('keydown', handleCustomer360Keydown);

    closeButton?.addEventListener('click', close);
    backdrop?.addEventListener('click', close);

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || !drawer.classList.contains('is-open')) {
            return;
        }

        const palette = drawer.querySelector('[data-c360-command-palette]');
        const shortcutHelp = drawer.querySelector('[data-c360-shortcut-help]');

        if (palette && !palette.hidden) {
            return;
        }

        if (shortcutHelp && !shortcutHelp.hidden) {
            return;
        }

        if (isMoreMenuOpen()) {
            event.preventDefault();
            closeMoreMenu();

            return;
        }

        event.preventDefault();
        close();
    });

    panel?.addEventListener('click', (event) => {
        if (event.target.closest('[data-workspace-trigger]')) {
            return;
        }

        event.stopPropagation();
    });

    customer360RefreshAbortController?.abort();
    customer360RefreshAbortController = new AbortController();

    document.addEventListener('customer360:refresh', (event) => {
        const incidentId = event.detail?.incidentId;

        if (!incidentId || !drawer.classList.contains('is-open')) {
            return;
        }

        if (String(activeIncidentId) === String(incidentId)) {
            refreshDrawerContent(incidentId);
        }
    }, { signal: customer360RefreshAbortController.signal });

    document.addEventListener('customer360:open', (event) => {
        const incidentId = event.detail?.incidentId;
        const referenceLabel = event.detail?.referenceLabel ?? '';

        if (!incidentId) {
            return;
        }

        open(incidentId, referenceLabel, {
            tab: event.detail?.tab ?? null,
            anchor: event.detail?.anchor ?? null,
            openMoreMenu: event.detail?.openMoreMenu ?? false,
        });
    }, { signal: customer360RefreshAbortController.signal });

    const autoOpenIncidentId = root.dataset.openCustomer360IncidentId?.trim() ?? '';
    const autoOpenMoreMenu = root.dataset.openCustomer360MoreMenu === '1';

    if (autoOpenIncidentId !== '') {
        window.setTimeout(() => {
            open(
                autoOpenIncidentId,
                root.dataset.openCustomer360Reference ?? '',
                { openMoreMenu: autoOpenMoreMenu },
            );
        }, 0);
    }

    return {
        open,
        close,
        isOpen: () => drawer.classList.contains('is-open'),
    };
};
