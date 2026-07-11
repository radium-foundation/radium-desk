export const C360_SUCCESS_CLOSE_DELAY_MS = 4500;

export const normalizeValue = (value) => {
    const trimmed = (value ?? '').trim();

    return trimmed === '' ? null : trimmed;
};

export const formatDisplayValue = (value) => {
    if (value === null || value === undefined || String(value).trim() === '') {
        return '—';
    }

    return String(value);
};

export const escapeHtml = (value) => String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');

export const buildFieldChanges = (form, fieldDefinitions) => {
    const changes = [];

    fieldDefinitions.forEach((field) => {
        const input = form.querySelector(`[name="${field.inputName}"]`);

        if (!(input instanceof HTMLInputElement || input instanceof HTMLTextAreaElement)) {
            return;
        }

        const original = normalizeValue(form.dataset[field.originalDatasetKey]);
        const next = normalizeValue(input.value);

        if (original === next) {
            return;
        }

        changes.push({
            label: field.label,
            current: original,
            next,
        });
    });

    return changes;
};

export const renderReviewCards = (listElement, changes) => {
    if (!listElement) {
        return;
    }

    if (changes.length === 0) {
        listElement.innerHTML = '';
        return;
    }

    listElement.innerHTML = changes.map((change) => `
        <article class="c360-dialog-review-card">
            <h4 class="c360-dialog-review-card-title">${escapeHtml(change.label)}</h4>
            <div class="c360-dialog-review-values">
                <div class="c360-dialog-review-value">
                    <span class="c360-dialog-review-value-label">Current</span>
                    <span class="c360-dialog-review-value-text">${escapeHtml(formatDisplayValue(change.current))}</span>
                </div>
                <div class="c360-dialog-review-arrow" aria-hidden="true">↓</div>
                <div class="c360-dialog-review-value c360-dialog-review-value--new">
                    <span class="c360-dialog-review-value-label">New</span>
                    <span class="c360-dialog-review-value-text">${escapeHtml(formatDisplayValue(change.next))}</span>
                </div>
            </div>
        </article>
    `).join('');
};

export const updateChangeStatus = (form, changes) => {
    const statusElement = form.querySelector('[data-c360-change-status]');
    const statusText = form.querySelector('[data-c360-change-status-text]');
    const hasChanges = changes.length > 0;

    if (statusElement instanceof HTMLElement) {
        statusElement.classList.toggle('c360-dialog-change-status--changed', hasChanges);
        statusElement.classList.toggle('c360-dialog-change-status--unchanged', !hasChanges);
    }

    if (statusText instanceof HTMLElement) {
        statusText.textContent = hasChanges ? '✓ Changed' : 'No changes detected';
    }

    return hasChanges;
};

export const initChangeDetection = (form, {
    fieldDefinitions,
    reviewButton = null,
    onChangesUpdate = null,
}) => {
    const resolvedReviewButton = reviewButton
        ?? form.querySelector('[data-correct-customer-details-review]');

    const refresh = () => {
        const changes = buildFieldChanges(form, fieldDefinitions);
        const hasChanges = updateChangeStatus(form, changes);

        if (resolvedReviewButton instanceof HTMLButtonElement) {
            resolvedReviewButton.disabled = !hasChanges;
        }

        onChangesUpdate?.(changes, hasChanges);

        return changes;
    };

    form.querySelectorAll('[data-c360-change-field]').forEach((field) => {
        field.addEventListener('input', refresh);
        field.addEventListener('change', refresh);
    });

    return refresh();
};

const parseSuccessItems = (dialogElement) => {
    const rawItems = dialogElement.dataset.c360SuccessItems ?? '';

    if (rawItems.trim() === '') {
        return [];
    }

    return rawItems.split('|').map((item) => item.trim()).filter(Boolean);
};

export const renderSuccessStateHtml = ({ title, items }) => `
    <div class="c360-dialog-success">
        <div class="c360-dialog-success-icon" aria-hidden="true">✓</div>
        <h3 class="c360-dialog-success-title">${escapeHtml(title)}</h3>
        ${items.length > 0 ? `
            <ul class="c360-dialog-success-list mb-0">
                ${items.map((item) => `
                    <li class="c360-dialog-success-item">
                        <span class="c360-dialog-success-check" aria-hidden="true">✓</span>
                        <span>${escapeHtml(item)}</span>
                    </li>
                `).join('')}
            </ul>
        ` : ''}
    </div>
`;

export const showSuccessState = async (host, dialogElement) => {
    const modalContent = host?.querySelector('[data-workspace-modal-content]');

    if (!(modalContent instanceof HTMLElement) || !(dialogElement instanceof HTMLElement)) {
        return false;
    }

    const title = dialogElement.dataset.c360SuccessTitle?.trim()
        || 'Changes saved successfully';
    const items = parseSuccessItems(dialogElement);

    modalContent.innerHTML = renderSuccessStateHtml({ title, items });

    await new Promise((resolve) => {
        window.setTimeout(resolve, C360_SUCCESS_CLOSE_DELAY_MS);
    });

    return true;
};

export const maybeShowSuccessState = async (host, data) => {
    if (!data?.success) {
        return false;
    }

    const modalContent = host?.querySelector('[data-workspace-modal-content]');
    const dialogElement = modalContent?.querySelector('[data-c360-dialog]');

    if (!(dialogElement instanceof HTMLElement)) {
        return false;
    }

    if (dialogElement.dataset.c360SuccessTitle === undefined
        && dialogElement.dataset.c360SuccessItems === undefined) {
        return false;
    }

    await showSuccessState(host, dialogElement);

    return true;
};
