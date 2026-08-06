/**
 * IRA Learning Center — compact row workspace interactions.
 * Bound to [data-ira-learning-center]; no page-shell coupling.
 */
const MOVE_MAP = {
    promotion: 'promotion',
    spam: 'spam',
    automatic: 'automatic',
};

const MENU_GAP = 4;
const MENU_EDGE = 8;

/** @type {{ root: HTMLElement, trigger: HTMLElement, menu: HTMLElement, home: HTMLElement, row: HTMLElement } | null} */
let openMenu = null;

function selectedIds(root) {
    return Array.from(root.querySelectorAll('[data-ira-row-select]:checked')).map((el) => el.value);
}

function syncPanels(root) {
    const action = root.querySelector('[data-ira-bulk-action]')?.value || '';
    const effective = action === 'move' ? 'move' : action;

    root.querySelectorAll('[data-ira-panel]').forEach((panel) => {
        const match = panel.getAttribute('data-ira-panel') === effective;
        panel.hidden = !match;
        panel.disabled = !match;
    });
}

function syncSelection(root) {
    const ids = selectedIds(root);
    const toolbar = root.querySelector('[data-ira-toolbar]');
    const holder = root.querySelector('[data-ira-selected-inputs]');
    const countEl = root.querySelector('[data-ira-selected-count]');
    const selectAll = root.querySelector('[data-ira-select-all]');
    const checks = Array.from(root.querySelectorAll('[data-ira-row-select]'));

    if (holder) {
        holder.innerHTML = ids
            .map((id) => `<input type="hidden" name="message_ids[]" value="${id}">`)
            .join('');
    }

    if (countEl) {
        countEl.textContent = String(ids.length);
    }

    if (toolbar) {
        toolbar.hidden = ids.length === 0;
    }

    if (selectAll && checks.length) {
        selectAll.checked = checks.every((input) => input.checked);
        selectAll.indeterminate = !selectAll.checked && checks.some((input) => input.checked);
    }

    syncPanels(root);
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function renderExpand(container, data) {
    const explanation = data.explanation || {};
    const examples = Array.isArray(explanation.examples) ? explanation.examples : [];

    container.innerHTML = `
        <div class="ira-lc-expand">
            <div class="ira-lc-expand__grid">
                <div>
                    <div class="ira-lc-expand__label">Full preview</div>
                    <div class="ira-lc-expand__value">${escapeHtml(data.preview)}</div>
                </div>
                <div>
                    <div class="ira-lc-expand__label">Existing customer</div>
                    <div class="ira-lc-expand__value">${escapeHtml(data.customer_label)}</div>
                </div>
                <div>
                    <div class="ira-lc-expand__label">Existing Service Case</div>
                    <div class="ira-lc-expand__value">${escapeHtml(data.service_case)}</div>
                </div>
                <div>
                    <div class="ira-lc-expand__label">Matched Learning Rule</div>
                    <div class="ira-lc-expand__value">${escapeHtml(data.matched_learning_rule)}</div>
                </div>
                <div>
                    <div class="ira-lc-expand__label">Previous confirmations</div>
                    <div class="ira-lc-expand__value">${escapeHtml(data.previous_confirmations)}</div>
                </div>
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
        </div>
    `;
}

function toggleRow(row) {
    const expand = row.querySelector('[data-ira-expand]');
    const toggle = row.querySelector('[data-ira-row-toggle]');
    if (!expand || !toggle) return;

    const opening = expand.hidden;
    row.classList.toggle('ira-lc-row--open', opening);
    expand.hidden = !opening;
    toggle.setAttribute('aria-expanded', opening ? 'true' : 'false');

    if (opening && !expand.dataset.rendered) {
        try {
            const data = JSON.parse(expand.getAttribute('data-expand-json') || '{}');
            renderExpand(expand, data);
            expand.dataset.rendered = '1';
            expand.removeAttribute('data-expand-json');
        } catch (e) {
            expand.textContent = 'Unable to load details.';
        }
    }
}

function prepareRowAction(root, row, action) {
    root.querySelectorAll('[data-ira-row-select]').forEach((input) => {
        input.checked = input.closest('[data-ira-row]') === row;
    });

    const actionSelect = root.querySelector('[data-ira-bulk-action]');
    if (!actionSelect) return;

    if (action === 'move') {
        actionSelect.value = 'move';
    } else if (action === 'importance') {
        actionSelect.value = 'importance';
        const importance = root.querySelector('[data-ira-panel="importance"]');
        if (importance) importance.value = 'high';
    } else {
        actionSelect.value = action;
    }

    if (action === 'assign') {
        const assignee = root.querySelector('[data-ira-panel="assign"]');
        const suggested = row.getAttribute('data-suggested-assignee');
        if (assignee && suggested) assignee.value = suggested;
    }

    syncSelection(root);
    root.querySelector('[data-ira-toolbar]')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
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

    const actionEl = event.target.closest('[data-ira-row-action]');
    if (!actionEl || !openMenu.menu.contains(actionEl)) {
        return false;
    }

    event.preventDefault();
    event.stopPropagation();

    const { root, row } = openMenu;
    const action = actionEl.getAttribute('data-ira-row-action');
    closeRowMenu();
    prepareRowAction(root, row, action);

    return true;
}

function boot(root) {
    if (root.dataset.iraBound === '1') {
        return;
    }
    root.dataset.iraBound = '1';

    const selectAll = root.querySelector('[data-ira-select-all]');

    selectAll?.addEventListener('change', () => {
        root.querySelectorAll('[data-ira-row-select]').forEach((input) => {
            input.checked = selectAll.checked;
        });
        syncSelection(root);
    });

    root.addEventListener('change', (event) => {
        if (event.target?.matches?.('[data-ira-row-select], [data-ira-bulk-action]')) {
            syncSelection(root);
        }
    });

    root.addEventListener('click', (event) => {
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
        const rowAction = event.target.closest('[data-ira-row-action]');
        const toggle = event.target.closest('[data-ira-row-toggle]');

        if (rowAction) {
            event.preventDefault();
            const row = rowAction.closest('[data-ira-row]');
            if (row) prepareRowAction(root, row, rowAction.getAttribute('data-ira-row-action'));
            return;
        }

        if (stop) return;

        if (toggle) {
            const row = toggle.closest('[data-ira-row]');
            if (row) toggleRow(row);
        }
    });

    // Actions fire from the body-ported menu (outside root).
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

        if ((event.key === 'Enter' || event.key === ' ') && event.target?.matches?.('[data-ira-row-toggle]')) {
            event.preventDefault();
            const row = event.target.closest('[data-ira-row]');
            if (row) toggleRow(row);
        }
    });

    root.querySelector('[data-ira-bulk-form]')?.addEventListener('submit', (event) => {
        const form = event.currentTarget;
        const actionSelect = form.querySelector('[data-ira-bulk-action]');
        const action = actionSelect?.value || '';

        if (selectedIds(root).length === 0) {
            event.preventDefault();
            return;
        }

        if (action === 'move') {
            const moveTo = form.querySelector('[data-ira-panel="move"]')?.value;
            const classification = MOVE_MAP[moveTo];
            if (!classification) {
                event.preventDefault();
                return;
            }

            actionSelect.value = 'classification';

            const classificationField = form.querySelector('[data-ira-panel="classification"]');
            if (classificationField) {
                classificationField.disabled = false;
                classificationField.hidden = false;
                classificationField.value = classification;
            }
        }

        syncSelection(root);
    });

    syncSelection(root);
}

document.querySelectorAll('[data-ira-learning-center]').forEach(boot);
