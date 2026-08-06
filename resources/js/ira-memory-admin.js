/**
 * Administration → IRA Memory interactions.
 * Bound to [data-ira-memory-admin]; no page-shell coupling.
 */

function selectedChecks(root) {
    return Array.from(root.querySelectorAll('[data-ira-memory-select]:checked'));
}

function syncMergeBar(root) {
    const bar = root.querySelector('[data-ira-memory-merge-bar]');
    const countEl = root.querySelector('[data-ira-memory-selected-count]');
    const survivor = root.querySelector('[data-ira-memory-survivor]');
    const sourceHolder = root.querySelector('[data-ira-memory-source-inputs]');
    const selectAll = root.querySelector('[data-ira-memory-select-all]');
    const checks = Array.from(root.querySelectorAll('[data-ira-memory-select]'));
    const selected = selectedChecks(root);

    if (countEl) {
        countEl.textContent = String(selected.length);
    }

    if (bar) {
        bar.hidden = selected.length < 2;
    }

    if (survivor) {
        const current = survivor.value;
        survivor.innerHTML = '<option value="">Choose survivor…</option>';
        selected.forEach((input) => {
            const option = document.createElement('option');
            option.value = input.value;
            option.textContent = `#${input.value} · ${input.getAttribute('data-label') || input.value}`;
            survivor.appendChild(option);
        });
        if (current && selected.some((input) => input.value === current)) {
            survivor.value = current;
        } else if (selected.length) {
            survivor.value = selected[0].value;
        }
    }

    if (sourceHolder) {
        sourceHolder.innerHTML = selected
            .map((input) => `<input type="hidden" name="source_ids[]" value="${input.value}">`)
            .join('');
    }

    if (selectAll && checks.length) {
        selectAll.checked = checks.every((input) => input.checked);
        selectAll.indeterminate = !selectAll.checked && checks.some((input) => input.checked);
    }
}

function syncDecisionPanels(root) {
    const kindSelect = root.querySelector('[data-ira-memory-decision-kind]');
    if (!kindSelect) {
        return;
    }

    const kind = kindSelect.value || '';
    const hidden = root.querySelector('[data-ira-memory-decision-value]');
    const panels = Array.from(root.querySelectorAll('[data-ira-memory-decision-panel]'));

    panels.forEach((panel) => {
        const match = panel.getAttribute('data-ira-memory-decision-panel') === kind;
        panel.hidden = !match;
        panel.disabled = !match;
        if (match && hidden) {
            hidden.value = panel.value || '';
        }
    });
}

function bindRoot(root) {
    root.addEventListener('change', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }

        if (target.matches('[data-ira-memory-select-all]')) {
            const checked = target.checked;
            root.querySelectorAll('[data-ira-memory-select]').forEach((input) => {
                input.checked = checked;
            });
            syncMergeBar(root);
            return;
        }

        if (target.matches('[data-ira-memory-select]')) {
            syncMergeBar(root);
            return;
        }

        if (target.matches('[data-ira-memory-decision-kind]')) {
            syncDecisionPanels(root);
            return;
        }

        if (target.matches('[data-ira-memory-decision-panel]')) {
            const hidden = root.querySelector('[data-ira-memory-decision-value]');
            if (hidden) {
                hidden.value = target.value || '';
            }
        }
    });

    syncMergeBar(root);
    syncDecisionPanels(root);
}

document.querySelectorAll('[data-ira-memory-admin]').forEach((root) => {
    if (root instanceof HTMLElement) {
        bindRoot(root);
    }
});
