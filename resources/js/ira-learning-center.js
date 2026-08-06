/**
 * IRA Learning Center — compact row workspace interactions.
 * Bound to [data-ira-learning-center]; no page-shell coupling.
 */
const MOVE_MAP = {
    promotion: 'promotion',
    spam: 'spam',
    automatic: 'automatic',
};

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

    root.addEventListener('keydown', (event) => {
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
