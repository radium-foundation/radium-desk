/**
 * Surgical KPI strip apply helpers.
 *
 * Live polls often return identical KPI HTML. Full innerHTML replacement
 * destroys tooltips, hover state, and focus — so skip or patch when possible.
 */

const TOOLTIP_RUNTIME_ATTRS = [
    'aria-describedby',
    'data-bs-original-title',
];

const METRIC_SELECTOR = [
    '.dashboard-kpi-value',
    '.dashboard-kpi-value-number',
    '.dashboard-email-intake-kpi__value',
    '.dashboard-email-intake-kpi__subtitle',
    '.dashboard-email-intake-kpi__hover-count',
    '.dashboard-email-intake-kpi__hover-label',
    '.agent-kpi-tile__value',
    '.agent-kpi-tile__meta',
    '.agent-kpi-tile__detail',
    '.agent-kpi-chip',
    '.agent-appointment-banner',
    '.agent-kpi-tile__title',
    '.dashboard-kpi-label',
    '.dashboard-email-intake-kpi__title',
].join(', ');

const normalizeWhitespace = (value) => String(value ?? '').replace(/\s+/g, ' ').trim();

const parseHtmlFragment = (html) => {
    const template = document.createElement('template');
    template.innerHTML = String(html ?? '').trim();

    return template.content;
};

const parseStripRoot = (html) => {
    const fragment = parseHtmlFragment(html);

    return fragment.querySelector('.dashboard-kpi-strip')
        ?? fragment.querySelector('.agent-dashboard-top')
        ?? fragment.firstElementChild;
};

const stripTooltipRuntimeAttrs = (root) => {
    if (!root) {
        return;
    }

    [root, ...root.querySelectorAll('*')].forEach((node) => {
        TOOLTIP_RUNTIME_ATTRS.forEach((attr) => {
            node.removeAttribute(attr);
        });
    });
};

export const kpiItemStructureKey = (element) => {
    if (!element) {
        return '';
    }

    const label = element.querySelector(
        '.dashboard-kpi-label, .agent-kpi-tile__title, .dashboard-email-intake-kpi__title',
    )?.textContent;

    return [
        element.tagName,
        element.dataset.workspace ?? '',
        element.getAttribute('href') ?? '',
        element.hasAttribute('data-email-intake-kpi') ? 'email-intake' : '',
        element.classList.contains('dashboard-kpi-item--total-users') ? 'total-users' : '',
        element.classList.contains('dashboard-kpi-item--online-users') ? 'online-users' : '',
        element.classList.contains('agent-kpi-tile--work') ? 'agent-work' : '',
        element.classList.contains('agent-kpi-tile--attention') ? 'agent-attention' : '',
        element.classList.contains('agent-kpi-tile--appointment') ? 'agent-appointment' : '',
        element.hasAttribute('data-agent-appointment-sticky') ? 'appointment-banner' : '',
        normalizeWhitespace(label),
    ].join('|');
};

export const kpiStripStructureSignature = (root) => {
    if (!root) {
        return '';
    }

    const agentRoot = root.classList.contains('agent-dashboard-top')
        ? root
        : root.querySelector('.agent-dashboard-top');

    if (agentRoot) {
        return [
            'agent',
            agentRoot.querySelector('.agent-appointment-banner-sticky-host') ? 'banner' : 'no-banner',
            ...Array.from(agentRoot.querySelectorAll('.agent-kpi-tile')).map(kpiItemStructureKey),
        ].join('>');
    }

    return Array.from(root.querySelectorAll('.dashboard-kpi-item, [data-email-intake-kpi]'))
        .map(kpiItemStructureKey)
        .join('>');
};

export const kpiStripMetricSignature = (root) => {
    if (!root) {
        return '';
    }

    const clone = root.cloneNode(true);
    stripTooltipRuntimeAttrs(clone);

    const metrics = Array.from(clone.querySelectorAll(METRIC_SELECTOR))
        .map((el) => normalizeWhitespace(el.textContent))
        .join('|');

    const itemClasses = Array.from(clone.querySelectorAll(
        '.dashboard-kpi-item, .agent-kpi-tile, [data-email-intake-kpi], .agent-appointment-banner-sticky-host',
    ))
        .map((el) => normalizeWhitespace(el.getAttribute('class')))
        .join('|');

    const aria = Array.from(clone.querySelectorAll('[aria-label]'))
        .map((el) => normalizeWhitespace(el.getAttribute('aria-label')))
        .join('|');

    const tooltipTemplates = Array.from(clone.querySelectorAll('.dashboard-tooltip-template'))
        .map((el) => normalizeWhitespace(el.innerHTML))
        .join('|');

    return [metrics, itemClasses, aria, tooltipTemplates].join('::');
};

export const kpiHtmlEquivalent = (currentRoot, nextRoot) => {
    if (!currentRoot || !nextRoot) {
        return false;
    }

    return kpiStripStructureSignature(currentRoot) === kpiStripStructureSignature(nextRoot)
        && kpiStripMetricSignature(currentRoot) === kpiStripMetricSignature(nextRoot);
};

const syncClassList = (current, next) => {
    const nextClass = next.getAttribute('class') ?? '';

    if ((current.getAttribute('class') ?? '') !== nextClass) {
        current.setAttribute('class', nextClass);
    }
};

const syncAttributes = (current, next, names) => {
    names.forEach((name) => {
        const nextValue = next.getAttribute(name);

        if (nextValue === null) {
            if (current.hasAttribute(name)) {
                current.removeAttribute(name);
            }

            return;
        }

        if (current.getAttribute(name) !== nextValue) {
            current.setAttribute(name, nextValue);
        }
    });
};

const patchMetricSubtree = (current, next) => {
    syncClassList(current, next);
    syncAttributes(current, next, [
        'aria-label',
        'href',
        'data-workspace',
        'data-bs-toggle',
        'data-dashboard-tooltip',
    ]);

    const currentMetrics = current.querySelectorAll(METRIC_SELECTOR);
    const nextMetrics = next.querySelectorAll(METRIC_SELECTOR);

    if (currentMetrics.length !== nextMetrics.length) {
        return false;
    }

    currentMetrics.forEach((node, index) => {
        const nextNode = nextMetrics[index];
        const nextText = nextNode.textContent ?? '';

        if (node.textContent !== nextText) {
            node.textContent = nextText;
        }

        syncClassList(node, nextNode);
    });

    const currentHover = current.querySelector('.dashboard-email-intake-kpi__hover');
    const nextHover = next.querySelector('.dashboard-email-intake-kpi__hover');

    if (currentHover && nextHover && currentHover.innerHTML !== nextHover.innerHTML) {
        currentHover.innerHTML = nextHover.innerHTML;
    }

    const currentChips = current.querySelector('.agent-kpi-tile__chips');
    const nextChips = next.querySelector('.agent-kpi-tile__chips');

    if (currentChips || nextChips) {
        if (!currentChips && nextChips) {
            return false;
        }

        if (currentChips && !nextChips) {
            currentChips.remove();
        } else if (currentChips && nextChips && currentChips.innerHTML !== nextChips.innerHTML) {
            currentChips.innerHTML = nextChips.innerHTML;
        }
    }

    return true;
};

const patchOnlineUserTooltipTemplate = (currentItem, nextItem, nextFragment) => {
    const currentTemplate = currentItem.nextElementSibling?.classList?.contains('dashboard-tooltip-template')
        ? currentItem.nextElementSibling
        : currentItem.parentElement?.querySelector('.dashboard-tooltip-template');

    const nextTemplate = nextItem.nextElementSibling?.classList?.contains('dashboard-tooltip-template')
        ? nextItem.nextElementSibling
        : nextFragment?.querySelector('.dashboard-tooltip-template');

    if (currentTemplate && nextTemplate && currentTemplate.innerHTML !== nextTemplate.innerHTML) {
        currentTemplate.innerHTML = nextTemplate.innerHTML;
    }
};

/**
 * Patch in place when structure matches. Returns false when a full replace is required.
 */
export const patchKpiStrip = (currentRoot, nextRoot, nextFragment = null) => {
    if (!currentRoot || !nextRoot) {
        return false;
    }

    if (kpiStripStructureSignature(currentRoot) !== kpiStripStructureSignature(nextRoot)) {
        return false;
    }

    // Agent strip includes appointment banner / empty states — replace when metrics change.
    if (currentRoot.querySelector('.agent-dashboard-top') || currentRoot.classList.contains('agent-dashboard-top')) {
        return kpiStripMetricSignature(currentRoot) === kpiStripMetricSignature(nextRoot);
    }

    const currentItems = Array.from(currentRoot.querySelectorAll('.dashboard-kpi-item, [data-email-intake-kpi]'));
    const nextItems = Array.from(nextRoot.querySelectorAll('.dashboard-kpi-item, [data-email-intake-kpi]'));

    if (currentItems.length !== nextItems.length) {
        return false;
    }

    for (let index = 0; index < currentItems.length; index += 1) {
        const currentItem = currentItems[index];
        const nextItem = nextItems[index];

        if (kpiItemStructureKey(currentItem) !== kpiItemStructureKey(nextItem)) {
            return false;
        }

        if (!patchMetricSubtree(currentItem, nextItem)) {
            return false;
        }

        if (currentItem.classList.contains('dashboard-kpi-item--online-users')) {
            patchOnlineUserTooltipTemplate(currentItem, nextItem, nextFragment);
        }
    }

    return true;
};

const resolveCurrentStrip = (host) => {
    if (!host) {
        return null;
    }

    return host.querySelector('.dashboard-kpi-strip')
        ?? host.querySelector('.agent-dashboard-top')
        ?? (host.classList.contains('dashboard-kpi-strip') ? host : null);
};

/**
 * @returns {'skipped' | 'patched' | 'replaced' | 'noop'}
 */
export const applyKpiStripDom = (targetId, html) => {
    if (html === undefined) {
        return 'noop';
    }

    const host = document.getElementById(targetId);

    if (!host) {
        return 'noop';
    }

    const fragment = parseHtmlFragment(html);
    const nextRoot = fragment.querySelector('.dashboard-kpi-strip')
        ?? fragment.querySelector('.agent-dashboard-top')
        ?? fragment.firstElementChild;

    // Plain-text / non-structured payloads (tests + legacy).
    if (!nextRoot) {
        if (host.innerHTML === String(html)) {
            return 'skipped';
        }

        host.innerHTML = html;

        return 'replaced';
    }

    const currentRoot = resolveCurrentStrip(host);

    if (currentRoot && kpiHtmlEquivalent(currentRoot, nextRoot)) {
        return 'skipped';
    }

    if (currentRoot && patchKpiStrip(currentRoot, nextRoot, fragment)) {
        return 'patched';
    }

    host.innerHTML = '';
    host.appendChild(nextRoot.cloneNode(true));

    // Keep non-strip siblings from the fragment (admin slot payloads use item + tooltip template).
    Array.from(fragment.childNodes).forEach((node) => {
        if (node === nextRoot) {
            return;
        }

        if (node.nodeType === Node.ELEMENT_NODE) {
            host.appendChild(node.cloneNode(true));
        }
    });

    return 'replaced';
};

/**
 * @returns {'skipped' | 'patched' | 'replaced' | 'noop'}
 */
export const applyAdminKpiSlotDom = (slotSelector, html) => {
    if (!html) {
        return 'noop';
    }

    const slot = document.querySelector(slotSelector);

    if (!slot) {
        return 'noop';
    }

    const fragment = parseHtmlFragment(html);
    const nextItem = fragment.querySelector('.dashboard-kpi-item') ?? fragment.firstElementChild;

    if (!nextItem) {
        if (slot.innerHTML === String(html)) {
            return 'skipped';
        }

        slot.innerHTML = html;

        return 'replaced';
    }

    const currentItem = slot.querySelector('.dashboard-kpi-item');

    if (currentItem && kpiHtmlEquivalent(currentItem, nextItem)) {
        const currentTemplate = slot.querySelector('.dashboard-tooltip-template');
        const nextTemplate = fragment.querySelector('.dashboard-tooltip-template');

        if (
            (!currentTemplate && !nextTemplate)
            || (currentTemplate && nextTemplate && normalizeWhitespace(currentTemplate.innerHTML) === normalizeWhitespace(nextTemplate.innerHTML))
        ) {
            return 'skipped';
        }
    }

    if (
        currentItem
        && kpiItemStructureKey(currentItem) === kpiItemStructureKey(nextItem)
        && patchMetricSubtree(currentItem, nextItem)
    ) {
        const currentTemplate = slot.querySelector('.dashboard-tooltip-template');
        const nextTemplate = fragment.querySelector('.dashboard-tooltip-template');

        if (currentTemplate && nextTemplate && currentTemplate.innerHTML !== nextTemplate.innerHTML) {
            currentTemplate.innerHTML = nextTemplate.innerHTML;
        } else if (!currentTemplate && nextTemplate) {
            slot.appendChild(nextTemplate.cloneNode(true));
        }

        return 'patched';
    }

    slot.innerHTML = html;

    return 'replaced';
};

export { parseStripRoot };
