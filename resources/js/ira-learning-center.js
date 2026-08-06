/**
 * IRA Learning Center — single-email review panel.
 * Teach (optional) + Disposition (required) share one Save.
 * Bound to [data-ira-learning-center]; no page-shell coupling.
 */

const MENU_GAP = 4;
const MENU_EDGE = 8;

/** @type {{ root: HTMLElement, trigger: HTMLElement, menu: HTMLElement, home: HTMLElement, row: HTMLElement } | null} */
let openMenu = null;

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function rowSubject(row) {
    return row.getAttribute('data-subject') || 'No subject';
}

function rowPreview(row) {
    const preview = row.getAttribute('data-preview');
    if (preview && preview.trim() !== '') {
        return preview;
    }

    return 'No preview available.';
}

function renderExpandBasics(container, subject, preview) {
    container.innerHTML = `
        <div class="ira-lc-expand">
            <div class="ira-lc-expand__basics">
                <div>
                    <div class="ira-lc-expand__label">Subject</div>
                    <div class="ira-lc-expand__value">${escapeHtml(subject)}</div>
                </div>
                <div>
                    <div class="ira-lc-expand__label">Preview</div>
                    <div class="ira-lc-expand__preview">${escapeHtml(preview)}</div>
                </div>
            </div>
            <div class="ira-lc-expand__details" data-ira-expand-details></div>
        </div>
    `;
}

function renderExpandDetails(detailsEl, data) {
    const explanation = data.explanation || {};
    const examples = Array.isArray(explanation.examples) ? explanation.examples : [];
    const automaticGroup = data.automatic_group
        ? `<div>
                <div class="ira-lc-expand__label">Automatic group</div>
                <div class="ira-lc-expand__value">${escapeHtml(data.automatic_group)}</div>
            </div>`
        : '';

    detailsEl.innerHTML = `
        <div class="ira-lc-expand__grid">
            <div>
                <div class="ira-lc-expand__label">Existing customer</div>
                <div class="ira-lc-expand__value">${escapeHtml(data.customer_label || '—')}</div>
            </div>
            <div>
                <div class="ira-lc-expand__label">Existing Service Case</div>
                <div class="ira-lc-expand__value">${escapeHtml(data.service_case || '—')}</div>
            </div>
            <div>
                <div class="ira-lc-expand__label">Matched Learning Rule</div>
                <div class="ira-lc-expand__value">${escapeHtml(data.matched_learning_rule || '—')}</div>
            </div>
            <div>
                <div class="ira-lc-expand__label">Previous confirmations</div>
                <div class="ira-lc-expand__value">${escapeHtml(data.previous_confirmations || '—')}</div>
            </div>
            ${automaticGroup}
        </div>
        <div class="ira-lc-expand__why">
            <div class="ira-lc-expand__label">Explainability</div>
            <div class="ira-lc-expand__value"><strong>Why:</strong> ${escapeHtml(explanation.why || data.why || '—')}</div>
            ${examples.length ? `<ul class="ira-lc-expand__examples">${examples.map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul>` : ''}
            <div class="ira-lc-expand__meta">
                <span>Matched sender: ${escapeHtml(explanation.matched_sender || '—')}</span>
                <span>Matched keyword: ${escapeHtml(explanation.matched_keyword || '—')}</span>
                <span>Previous confirmation: ${explanation.previous_operator_confirmation ? 'Yes' : 'No'}</span>
                <span>Rule confidence: ${explanation.rule_confidence != null ? escapeHtml(explanation.rule_confidence) + '%' : '—'}</span>
            </div>
        </div>
    `;
}

function renderExpandDetailsError(detailsEl) {
    detailsEl.innerHTML = `
        <div class="ira-lc-expand__error">
            <span>Unable to load additional details.</span>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-ira-expand-retry>Retry</button>
        </div>
    `;
}

function loadExpandDetails(row, expand) {
    const detailsEl = expand.querySelector('[data-ira-expand-details]');
    if (!detailsEl) return;

    const raw = expand.getAttribute('data-expand-json');
    if (!raw) {
        if (expand.dataset.detailsOk === '1') {
            return;
        }
        renderExpandDetailsError(detailsEl);
        return;
    }

    try {
        const data = JSON.parse(raw);
        renderExpandDetails(detailsEl, data || {});
        expand.dataset.detailsOk = '1';
        expand.removeAttribute('data-expand-json');
    } catch (e) {
        expand.dataset.detailsOk = '0';
        renderExpandDetailsError(detailsEl);
    }
}

function toggleRow(row) {
    const expand = row.querySelector('[data-ira-expand]');
    const toggle = row.querySelector('[data-ira-row-toggle]');
    if (!expand || !toggle) return;

    const opening = expand.hidden;
    row.classList.toggle('ira-lc-row--open', opening);
    expand.hidden = !opening;
    toggle.setAttribute('aria-expanded', opening ? 'true' : 'false');

    if (!opening) {
        return;
    }

    if (!expand.dataset.basicsRendered) {
        renderExpandBasics(expand, rowSubject(row), rowPreview(row));
        expand.dataset.basicsRendered = '1';
    }

    if (expand.dataset.detailsOk !== '1') {
        loadExpandDetails(row, expand);
    }
}

function syncDispositionPanels(form) {
    const disposition = form.querySelector('[data-ira-review-disposition]')?.value || '';

    form.querySelectorAll('[data-ira-review-disp-panel]').forEach((panel) => {
        const match = panel.getAttribute('data-ira-review-disp-panel') === disposition;
        panel.hidden = !match;
        const input = panel.querySelector('[data-ira-review-disp-input]');
        if (!input) return;
        input.disabled = !match;
        if (input.tagName === 'INPUT') {
            input.required = match;
        }
    });
}

function clearReviewSelection(root) {
    root.querySelectorAll('[data-ira-row].ira-lc-row--selected').forEach((row) => {
        row.classList.remove('ira-lc-row--selected');
        row.querySelector('[data-ira-row-select-trigger]')?.setAttribute('aria-pressed', 'false');
    });
}

function closeReviewPanel(root) {
    const form = root.querySelector('[data-ira-review-form]');
    if (!form) return;

    form.hidden = true;
    form.classList.remove('ira-lc-review--open');
    clearReviewSelection(root);

    const messageInput = form.querySelector('[data-ira-review-message-id]');
    if (messageInput) messageInput.value = '';

    const disposition = form.querySelector('[data-ira-review-disposition]');
    if (disposition) disposition.value = '';

    form.querySelectorAll('[data-ira-review-teach]').forEach((el) => {
        el.value = '';
    });

    syncDispositionPanels(form);
}

function openReviewPanel(root, row) {
    const form = root.querySelector('[data-ira-review-form]');
    if (!form || !row?.hasAttribute?.('data-ira-reviewable')) {
        return;
    }

    clearReviewSelection(root);
    row.classList.add('ira-lc-row--selected');
    row.querySelector('[data-ira-row-select-trigger]')?.setAttribute('aria-pressed', 'true');

    const messageId = row.getAttribute('data-message-id') || '';
    const ownerId = row.getAttribute('data-owner-id') || '';
    const classification = row.getAttribute('data-classification') || '';
    const importance = row.getAttribute('data-importance') || 'normal';
    const subject = rowSubject(row);
    const sender = row.getAttribute('data-sender') || row.getAttribute('data-sender-email') || '';
    const preview = rowPreview(row);

    const messageInput = form.querySelector('[data-ira-review-message-id]');
    if (messageInput) messageInput.value = messageId;

    form.querySelector('[data-ira-baseline="assignee"]').value = ownerId;
    form.querySelector('[data-ira-baseline="classification"]').value = classification;
    form.querySelector('[data-ira-baseline="importance"]').value = importance;

    const assignee = form.querySelector('[data-ira-review-teach="assignee"]');
    const classificationEl = form.querySelector('[data-ira-review-teach="classification"]');
    const importanceEl = form.querySelector('[data-ira-review-teach="importance"]');

    if (assignee) assignee.value = ownerId;
    if (classificationEl) classificationEl.value = classification;
    if (importanceEl) importanceEl.value = importance;

    const subjectEl = form.querySelector('[data-ira-review-subject]');
    const senderEl = form.querySelector('[data-ira-review-sender]');
    const previewEl = form.querySelector('[data-ira-review-preview]');
    if (subjectEl) subjectEl.textContent = subject;
    if (senderEl) senderEl.textContent = sender;
    if (previewEl) previewEl.textContent = preview;

    const dispositionOwner = form.querySelector('[data-ira-review-disp-input="create_case"]');
    const suggested = row.getAttribute('data-suggested-assignee') || ownerId;
    if (dispositionOwner && suggested) {
        dispositionOwner.value = suggested;
    }

    const disposition = form.querySelector('[data-ira-review-disposition]');
    if (disposition) disposition.value = '';

    syncDispositionPanels(form);

    form.hidden = false;
    form.classList.add('ira-lc-review--open');
    form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    form.querySelector('[data-ira-review-disposition]')?.focus();
}

function menuItems(menu) {
    return Array.from(menu.querySelectorAll('[role="menuitem"]')).filter(
        (item) => !item.classList.contains('disabled') && item.getAttribute('aria-disabled') !== 'true',
    );
}

function positionOpenMenu() {
    if (!openMenu) return;

    const { trigger, menu } = openMenu;
    const rect = trigger.getBoundingClientRect();
    const menuWidth = menu.offsetWidth;
    const menuHeight = menu.offsetHeight;
    const viewportW = window.innerWidth;
    const viewportH = window.innerHeight;
    const spaceBelow = viewportH - rect.bottom - MENU_GAP;
    const spaceAbove = rect.top - MENU_GAP;
    const openUp = menuHeight > spaceBelow && spaceAbove > spaceBelow;

    let top = openUp ? rect.top - menuHeight - MENU_GAP : rect.bottom + MENU_GAP;
    let left = rect.right - menuWidth;

    left = Math.max(MENU_EDGE, Math.min(left, viewportW - menuWidth - MENU_EDGE));
    top = Math.max(MENU_EDGE, Math.min(top, viewportH - menuHeight - MENU_EDGE));

    menu.style.top = `${Math.round(top)}px`;
    menu.style.left = `${Math.round(left)}px`;
    menu.dataset.placement = openUp ? 'top' : 'bottom';
}

function closeRowMenu() {
    if (!openMenu) return;

    const { trigger, menu, home } = openMenu;

    menu.hidden = true;
    menu.classList.remove('ira-lc-menu--open');
    menu.removeAttribute('data-placement');
    menu.style.top = '';
    menu.style.left = '';
    menu.style.minWidth = '';

    if (menu.parentElement !== home) {
        home.appendChild(menu);
    }

    trigger.setAttribute('aria-expanded', 'false');
    openMenu = null;

    document.removeEventListener('pointerdown', onMenuPointerDown, true);
    document.removeEventListener('keydown', onMenuKeyDown, true);
    window.removeEventListener('resize', onMenuViewportChange);
    window.removeEventListener('scroll', onMenuViewportChange, true);
}

function onMenuPointerDown(event) {
    if (!openMenu) return;

    const target = event.target;
    if (openMenu.menu.contains(target) || openMenu.trigger.contains(target)) {
        return;
    }

    closeRowMenu();
}

function onMenuViewportChange() {
    if (!openMenu) return;
    closeRowMenu();
}

function onMenuKeyDown(event) {
    if (!openMenu) return;

    const items = menuItems(openMenu.menu);
    const active = document.activeElement;
    const index = items.indexOf(active);

    if (event.key === 'Escape') {
        event.preventDefault();
        const trigger = openMenu.trigger;
        closeRowMenu();
        trigger.focus();
        return;
    }

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        const next = items[(index + 1 + items.length) % items.length] || items[0];
        next?.focus();
        return;
    }

    if (event.key === 'ArrowUp') {
        event.preventDefault();
        const prev = items[(index - 1 + items.length) % items.length] || items[items.length - 1];
        prev?.focus();
        return;
    }

    if (event.key === 'Home') {
        event.preventDefault();
        items[0]?.focus();
        return;
    }

    if (event.key === 'End') {
        event.preventDefault();
        items[items.length - 1]?.focus();
        return;
    }

    if (event.key === 'Tab') {
        closeRowMenu();
    }
}

function openRowMenu(root, trigger) {
    const wrap = trigger.closest('.ira-lc-menu-wrap');
    const menu = wrap?.querySelector('[data-ira-menu]');
    const row = trigger.closest('[data-ira-row]');

    if (!wrap || !menu || !row) return;

    if (openMenu?.trigger === trigger) {
        closeRowMenu();
        return;
    }

    closeRowMenu();

    const minWidth = Math.max(wrap.offsetWidth, 152);
    document.body.appendChild(menu);
    menu.hidden = false;
    menu.classList.add('ira-lc-menu--open');
    menu.style.minWidth = `${minWidth}px`;

    openMenu = { root, trigger, menu, home: wrap, row };
    trigger.setAttribute('aria-expanded', 'true');

    positionOpenMenu();

    document.addEventListener('pointerdown', onMenuPointerDown, true);
    document.addEventListener('keydown', onMenuKeyDown, true);
    window.addEventListener('resize', onMenuViewportChange);
    window.addEventListener('scroll', onMenuViewportChange, true);

    menuItems(menu)[0]?.focus();
}

function handleMenuAction(event) {
    if (!openMenu) return false;

    const reviewEl = event.target.closest('[data-ira-open-review]');
    const toggleDetails = event.target.closest('[data-ira-row-toggle-menu]');
    if ((!reviewEl && !toggleDetails) || !openMenu.menu.contains(reviewEl || toggleDetails)) {
        return false;
    }

    event.preventDefault();
    event.stopPropagation();

    const { root, row } = openMenu;
    closeRowMenu();

    if (reviewEl) {
        openReviewPanel(root, row);
    } else if (toggleDetails) {
        toggleRow(row);
    }

    return true;
}

function boot(root) {
    if (root.dataset.iraBound === '1') {
        return;
    }
    root.dataset.iraBound = '1';

    const form = root.querySelector('[data-ira-review-form]');

    form?.querySelector('[data-ira-review-disposition]')?.addEventListener('change', () => {
        syncDispositionPanels(form);
    });

    form?.querySelector('[data-ira-review-close]')?.addEventListener('click', () => {
        closeReviewPanel(root);
    });

    form?.addEventListener('submit', (event) => {
        const messageId = form.querySelector('[data-ira-review-message-id]')?.value;
        const disposition = form.querySelector('[data-ira-review-disposition]')?.value;
        if (!messageId || !disposition) {
            event.preventDefault();
            return;
        }

        if (root.getAttribute('data-current-queue') === 'spam') {
            const ok = window.confirm(
                'This email will be removed from Spam and returned to Needs Review.',
            );
            if (!ok) {
                event.preventDefault();
            }
        }
    });

    root.addEventListener('click', (event) => {
        const retry = event.target.closest('[data-ira-expand-retry]');
        if (retry) {
            event.preventDefault();
            event.stopPropagation();
            const row = retry.closest('[data-ira-row]');
            const expand = row?.querySelector('[data-ira-expand]');
            if (row && expand) {
                expand.dataset.detailsOk = '0';
                loadExpandDetails(row, expand);
            }
            return;
        }

        if (handleMenuAction(event)) {
            return;
        }

        const menuTrigger = event.target.closest('[data-ira-menu-trigger]');
        if (menuTrigger && root.contains(menuTrigger)) {
            event.preventDefault();
            event.stopPropagation();
            openRowMenu(root, menuTrigger);
            return;
        }

        const stop = event.target.closest('[data-ira-stop]');
        if (stop) return;

        const selectTrigger = event.target.closest('[data-ira-row-select-trigger]');
        if (selectTrigger) {
            const row = selectTrigger.closest('[data-ira-row]');
            if (row) openReviewPanel(root, row);
            return;
        }

        const toggle = event.target.closest('[data-ira-row-toggle]');
        if (toggle) {
            const row = toggle.closest('[data-ira-row]');
            if (row) toggleRow(row);
        }
    });

    document.addEventListener('click', (event) => {
        if (!openMenu || openMenu.root !== root) return;
        handleMenuAction(event);
    });

    root.addEventListener('keydown', (event) => {
        if ((event.key === 'Enter' || event.key === ' ') && event.target?.matches?.('[data-ira-menu-trigger]')) {
            event.preventDefault();
            openRowMenu(root, event.target);
            return;
        }

        if ((event.key === 'Enter' || event.key === ' ') && event.target?.matches?.('[data-ira-row-select-trigger]')) {
            event.preventDefault();
            const row = event.target.closest('[data-ira-row]');
            if (row) openReviewPanel(root, row);
            return;
        }

        if ((event.key === 'Enter' || event.key === ' ') && event.target?.matches?.('[data-ira-row-toggle]')) {
            event.preventDefault();
            const row = event.target.closest('[data-ira-row]');
            if (row) toggleRow(row);
        }

        if (event.key === 'Escape' && form && !form.hidden) {
            closeReviewPanel(root);
        }
    });
}

document.querySelectorAll('[data-ira-learning-center]').forEach(boot);
