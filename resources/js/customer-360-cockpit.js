import { closeMenu as closeMoreMenu } from './customer-360-more-menu';

const SEARCH_FIELDS = [
    { key: 'sc', label: 'SC Number', icon: 'bi-hash' },
    { key: 'reference', label: 'Reference Number', icon: 'bi-tag' },
    { key: 'orderId', label: 'Order ID', icon: 'bi-box' },
    { key: 'phone', label: 'Phone', icon: 'bi-telephone' },
    { key: 'email', label: 'Email', icon: 'bi-envelope' },
    { key: 'serial', label: 'Serial', icon: 'bi-upc' },
    { key: 'customerName', label: 'Customer Name', icon: 'bi-person' },
];

const isTypingTarget = (target) => {
    if (!(target instanceof Element)) {
        return false;
    }

    return Boolean(target.closest('input, textarea, select, [contenteditable="true"]'));
};

const normalizeQuery = (value) => value.trim().toLowerCase();

const parseSearchIndex = (contentHost) => {
    const cockpit = contentHost.querySelector('[data-customer-360-content]') ?? contentHost;
    const raw = cockpit.dataset.c360SearchIndex ?? '';

    if (raw === '') {
        return null;
    }

    try {
        return JSON.parse(raw);
    } catch {
        return null;
    }
};

const buildSearchResults = (index, query) => {
    if (!index) {
        return [];
    }

    const normalizedQuery = normalizeQuery(query);
    const results = [];

    SEARCH_FIELDS.forEach((field) => {
        const value = index[field.key];

        if (!value) {
            return;
        }

        const haystack = String(value).toLowerCase();

        if (normalizedQuery === '' || haystack.includes(normalizedQuery)) {
            results.push({
                id: `field-${field.key}`,
                type: 'field',
                label: field.label,
                value: String(value),
                icon: field.icon,
                score: normalizedQuery === '' ? 0 : (haystack.startsWith(normalizedQuery) ? 2 : 1),
            });
        }
    });

    (index.actions ?? []).forEach((action) => {
        const keywords = [action.label, ...(action.keywords ?? [])]
            .join(' ')
            .toLowerCase();

        if (normalizedQuery === '' || keywords.includes(normalizedQuery)) {
            results.push({
                id: action.id,
                type: 'action',
                label: action.label,
                icon: action.icon ?? 'bi-lightning',
                action,
                score: normalizedQuery === '' ? 0 : (action.label.toLowerCase().startsWith(normalizedQuery) ? 2 : 1),
            });
        }
    });

    return results
        .sort((left, right) => right.score - left.score || left.label.localeCompare(right.label))
        .slice(0, 12);
};

const renderPaletteResults = (resultsContainer, results, activeIndex) => {
    resultsContainer.innerHTML = '';

    if (results.length === 0) {
        const empty = document.createElement('li');
        empty.className = 'c360-command-palette-empty';
        empty.setAttribute('role', 'presentation');
        empty.textContent = 'No matches — try SC number, phone, or an action name.';
        resultsContainer.appendChild(empty);

        return;
    }

    results.forEach((result, index) => {
        const item = document.createElement('li');
        item.className = 'c360-command-palette-result';
        item.setAttribute('role', 'option');
        item.id = `c360-command-palette-option-${index}`;
        item.dataset.c360CommandPaletteOption = String(index);
        item.setAttribute('aria-selected', index === activeIndex ? 'true' : 'false');

        if (index === activeIndex) {
            item.classList.add('is-active');
        }

        const icon = document.createElement('i');
        icon.className = `bi ${result.icon} c360-command-palette-result-icon`;
        icon.setAttribute('aria-hidden', 'true');

        const copy = document.createElement('div');
        copy.className = 'c360-command-palette-result-copy';

        const label = document.createElement('span');
        label.className = 'c360-command-palette-result-label';
        label.textContent = result.label;
        copy.appendChild(label);

        if (result.type === 'field') {
            const value = document.createElement('span');
            value.className = 'c360-command-palette-result-value';
            value.textContent = result.value;
            copy.appendChild(value);
        }

        item.append(icon, copy);

        if (result.type === 'action') {
            const badge = document.createElement('span');
            badge.className = 'c360-command-palette-result-badge';
            badge.textContent = result.action?.type === 'status' ? 'Sent' : 'Action';
            item.appendChild(badge);
        }

        resultsContainer.appendChild(item);
    });
};

export const bindIraDisclosures = (root) => {
    const scope = root ?? document;

    scope.querySelectorAll('[data-c360-ira-collapse]').forEach((details) => {
        if (details.dataset.c360IraCollapseBound === 'true') {
            return;
        }

        details.dataset.c360IraCollapseBound = 'true';

        details.addEventListener('toggle', () => {
            if (!(details instanceof HTMLDetailsElement)) {
                return;
            }

            details.classList.toggle('is-expanded', details.open);
        });
    });
};

export const initCustomer360Cockpit = ({
    drawer,
    contentHost,
    activateTab,
    showToast,
    isOpen,
} = {}) => {
    if (!drawer || !contentHost) {
        return null;
    }

    const palette = drawer.querySelector('[data-c360-command-palette]');
    const paletteInput = palette?.querySelector('[data-c360-command-palette-input]');
    const paletteResults = palette?.querySelector('[data-c360-command-palette-results]');
    const paletteBackdrop = palette?.querySelector('[data-c360-command-palette-backdrop]');
    const shortcutHelp = drawer.querySelector('[data-c360-shortcut-help]');
    const shortcutHelpBackdrop = shortcutHelp?.querySelector('[data-c360-shortcut-help-backdrop]');
    const shortcutHelpClose = shortcutHelp?.querySelector('[data-c360-shortcut-help-close]');
    const modKeyNodes = drawer.querySelectorAll('[data-c360-mod-key]');

    let paletteResultsCache = [];
    let paletteActiveIndex = 0;
    let paletteOpen = false;
    let shortcutHelpOpen = false;

    const isMac = /Mac|iPhone|iPad|iPod/.test(navigator.platform);
    modKeyNodes.forEach((node) => {
        node.textContent = isMac ? '⌘' : 'Ctrl+';
    });

    const getTabRoot = () => contentHost.querySelector('[data-customer-360-content]') ?? contentHost;

    const clickTab = (tabKey) => {
        const tab = getTabRoot().querySelector(`[data-customer-360-tab="${tabKey}"]`);

        if (tab instanceof HTMLElement) {
            tab.click();
        } else if (typeof activateTab === 'function') {
            activateTab(tabKey);
        }
    };

    const triggerWorkspaceAction = (triggerName) => {
        const button = contentHost.querySelector(
            `[data-workspace-trigger="${triggerName}"][data-workspace-context="customer"]`,
        );

        if (button instanceof HTMLElement) {
            if (button.disabled) {
                showToast?.(button.title?.replace(/^🔒\s*/, '') || 'Action is not available for this case.', 'danger');

                return false;
            }

            button.click();

            return true;
        }

        showToast?.('Action is not available for this case.', 'danger');

        return false;
    };

    const runShortcutAction = (actionKey) => {
        const target = contentHost.querySelector(`[data-c360-shortcut-action="${actionKey}"]`);

        if (!(target instanceof HTMLElement)) {
            if (actionKey === 'email') {
                showToast?.('Email integration coming soon.');
            }

            return false;
        }

        if (target instanceof HTMLAnchorElement && target.href) {
            if (target.target === '_blank') {
                window.open(target.href, '_blank', 'noopener,noreferrer');
            } else {
                target.click();
            }

            return true;
        }

        if (!target.disabled) {
            target.click();
            return true;
        }

        return false;
    };

    const executePaletteResult = (result) => {
        if (!result) {
            return;
        }

        closePalette();

        if (result.type === 'field') {
            navigator.clipboard.writeText(result.value).then(() => {
                showToast?.(`${result.label} copied`);
            }).catch(() => {
                showToast?.('Unable to copy value.', 'danger');
            });

            return;
        }

        const action = result.action ?? {};

        if (action.type === 'status') {
            return;
        }

        if (action.type === 'link' && action.href) {
            window.open(action.href, '_blank', 'noopener,noreferrer');

            return;
        }

        if (action.type === 'trigger' && action.trigger) {
            if (action.enabled === false) {
                showToast?.(action.disabledReason ?? 'Action is not available for this case.', 'danger');

                return;
            }

            triggerWorkspaceAction(action.trigger);

            return;
        }

        if (action.type === 'tab') {
            clickTab(action.tab ?? 'overview');

            if (action.anchor) {
                window.setTimeout(() => {
                    document.getElementById(action.anchor)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 120);
            }
        }
    };

    const refreshPaletteResults = () => {
        if (!(paletteInput instanceof HTMLInputElement) || !paletteResults) {
            return;
        }

        const index = parseSearchIndex(contentHost);
        paletteResultsCache = buildSearchResults(index, paletteInput.value);
        paletteActiveIndex = 0;
        renderPaletteResults(paletteResults, paletteResultsCache, paletteActiveIndex);

        if (paletteInput instanceof HTMLInputElement) {
            paletteInput.setAttribute(
                'aria-activedescendant',
                paletteResultsCache.length > 0 ? 'c360-command-palette-option-0' : '',
            );
        }
    };

    const openPalette = () => {
        if (!palette || !(paletteInput instanceof HTMLInputElement)) {
            return;
        }

        closeMoreMenu();

        palette.hidden = false;
        palette.classList.add('is-open');
        paletteOpen = true;
        paletteInput.value = '';
        refreshPaletteResults();

        window.requestAnimationFrame(() => {
            paletteInput.focus();
            paletteInput.select();
        });
    };

    const closePalette = () => {
        if (!palette) {
            return;
        }

        palette.hidden = true;
        palette.classList.remove('is-open');
        paletteOpen = false;
        paletteResultsCache = [];
        paletteActiveIndex = 0;

        if (paletteInput instanceof HTMLInputElement) {
            paletteInput.value = '';
            paletteInput.setAttribute('aria-activedescendant', '');
        }
    };

    const openShortcutHelp = () => {
        if (!shortcutHelp) {
            return;
        }

        closePalette();
        shortcutHelp.hidden = false;
        shortcutHelp.classList.add('is-open');
        shortcutHelpOpen = true;
        shortcutHelpClose?.focus();
    };

    const closeShortcutHelp = () => {
        if (!shortcutHelp) {
            return;
        }

        shortcutHelp.hidden = true;
        shortcutHelp.classList.remove('is-open');
        shortcutHelpOpen = false;
    };

    const movePaletteSelection = (delta) => {
        if (paletteResultsCache.length === 0) {
            return;
        }

        paletteActiveIndex = (paletteActiveIndex + delta + paletteResultsCache.length) % paletteResultsCache.length;
        renderPaletteResults(paletteResults, paletteResultsCache, paletteActiveIndex);

        if (paletteInput instanceof HTMLInputElement) {
            paletteInput.setAttribute('aria-activedescendant', `c360-command-palette-option-${paletteActiveIndex}`);
        }

        const activeNode = paletteResults?.querySelector('.c360-command-palette-result.is-active');

        activeNode?.scrollIntoView({ block: 'nearest' });
    };

    paletteInput?.addEventListener('input', refreshPaletteResults);

    paletteInput?.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            movePaletteSelection(1);
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            movePaletteSelection(-1);
        }

        if (event.key === 'Enter') {
            event.preventDefault();
            executePaletteResult(paletteResultsCache[paletteActiveIndex]);
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            closePalette();
        }
    });

    paletteResults?.addEventListener('click', (event) => {
        const option = event.target.closest('[data-c360-command-palette-option]');

        if (!option) {
            return;
        }

        const index = Number(option.dataset.c360CommandPaletteOption);
        executePaletteResult(paletteResultsCache[index]);
    });

    paletteBackdrop?.addEventListener('click', closePalette);

    shortcutHelpBackdrop?.addEventListener('click', closeShortcutHelp);
    shortcutHelpClose?.addEventListener('click', closeShortcutHelp);

    contentHost.addEventListener('click', (event) => {
        const openTabButton = event.target.closest('[data-c360-empty-open-tab]');

        if (openTabButton instanceof HTMLElement) {
            event.preventDefault();
            clickTab(openTabButton.dataset.c360EmptyOpenTab ?? 'overview');
        }

        const focusFilters = event.target.closest('[data-c360-empty-focus-timeline-filters]');

        if (focusFilters instanceof HTMLElement) {
            event.preventDefault();
            clickTab('timeline');
            window.setTimeout(() => {
                contentHost.querySelector('[data-timeline-filter-chip]')?.focus();
            }, 200);
        }
    });

    const handleKeydown = (event) => {
        if (!isOpen?.()) {
            return;
        }

        const hasModifier = event.metaKey || event.ctrlKey;

        if (hasModifier && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            paletteOpen ? closePalette() : openPalette();

            return;
        }

        if (shortcutHelpOpen && event.key === 'Escape') {
            event.preventDefault();
            closeShortcutHelp();

            return;
        }

        if (paletteOpen) {
            return;
        }

        if (event.key === 'Escape') {
            if (shortcutHelpOpen) {
                event.preventDefault();
                closeShortcutHelp();
            }

            return;
        }

        if (isTypingTarget(event.target)) {
            return;
        }

        if (event.key === '?') {
            event.preventDefault();
            openShortcutHelp();

            return;
        }

        const shortcutMap = {
            c: 'call',
            w: 'whatsapp',
            e: 'email',
            s: 'correct-serial',
            d: 'correct-customer',
        };

        const actionKey = shortcutMap[event.key.toLowerCase()];

        if (actionKey) {
            event.preventDefault();
            runShortcutAction(actionKey);

            return;
        }

        if (event.key.toLowerCase() === 't') {
            event.preventDefault();
            clickTab('timeline');

            return;
        }

        if (event.key.toLowerCase() === 'i') {
            event.preventDefault();
            clickTab('ai-assistant');

            return;
        }

        if (event.key.toLowerCase() === 'o') {
            event.preventDefault();
            clickTab('overview');
        }
    };

    document.addEventListener('keydown', handleKeydown);
    bindIraDisclosures(contentHost);

    return {
        openPalette,
        closePalette,
        openShortcutHelp,
        closeShortcutHelp,
        destroy: () => {
            document.removeEventListener('keydown', handleKeydown);
        },
    };
};

export const buildSmartToastActions = (message) => {
    const actions = [];
    const normalized = String(message ?? '').toLowerCase();

    const openTimeline = () => {
        document.querySelector('[data-customer-360-drawer].is-open [data-customer-360-tab="timeline"]')?.click();
    };

    const viewAudit = () => {
        openTimeline();
        window.setTimeout(() => {
            const drawer = document.querySelector('[data-customer-360-drawer].is-open');

            drawer?.querySelector('[data-timeline-filter-chip="system"]')?.click();
            drawer?.querySelector('[data-timeline-filter-chip="system"]')?.focus();
        }, 250);
    };

    if (/customer details updated|serial number corrected|serial corrected|changes saved/i.test(normalized)) {
        actions.push({
            label: 'Open Timeline',
            onClick: openTimeline,
        });
    }

    if (/customer details updated|serial number corrected/i.test(normalized)) {
        actions.push({
            label: 'View Audit',
            onClick: viewAudit,
        });
    }

    return actions;
};
