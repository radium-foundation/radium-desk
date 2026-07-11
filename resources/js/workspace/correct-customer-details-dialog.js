const FIELD_DEFINITIONS = [
    {
        key: 'customer_name',
        label: 'Customer Name',
        inputName: 'customer_name',
        originalDatasetKey: 'originalCustomerName',
    },
    {
        key: 'customer_phone',
        label: 'Customer Phone',
        inputName: 'customer_phone',
        originalDatasetKey: 'originalCustomerPhone',
    },
    {
        key: 'customer_email',
        label: 'Customer Email',
        inputName: 'customer_email',
        originalDatasetKey: 'originalCustomerEmail',
    },
];

const normalizeValue = (value) => {
    const trimmed = (value ?? '').trim();

    return trimmed === '' ? null : trimmed;
};

const formatDisplayValue = (value) => {
    if (value === null || value === undefined || String(value).trim() === '') {
        return '—';
    }

    return String(value);
};

const buildChanges = (form) => {
    const changes = [];

    FIELD_DEFINITIONS.forEach((field) => {
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

const renderReviewList = (listElement, changes) => {
    if (!listElement) {
        return;
    }

    if (changes.length === 0) {
        listElement.innerHTML = '';
        return;
    }

    listElement.innerHTML = changes.map((change) => `
        <div class="correct-customer-details-review-item mb-3">
            <div class="small text-muted mb-1">${change.label}</div>
            <div class="small">
                <span class="text-muted">${formatDisplayValue(change.current)}</span>
                <span class="mx-2" aria-hidden="true">→</span>
                <strong>${formatDisplayValue(change.next)}</strong>
            </div>
        </div>
    `).join('');
};

const setStep = (form, step) => {
    const editStep = form.querySelector('[data-correct-customer-details-step="edit"]');
    const reviewStep = form.querySelector('[data-correct-customer-details-step="review"]');
    const reviewButton = form.querySelector('[data-correct-customer-details-review]');
    const backButton = form.querySelector('[data-correct-customer-details-back]');
    const confirmButton = form.querySelector('[data-correct-customer-details-confirm]');
    const noChangesAlert = form.querySelector('[data-correct-customer-details-no-changes]');
    const reviewList = form.querySelector('[data-correct-customer-details-review-list]');

    const isReview = step === 'review';
    const changes = buildChanges(form);

    editStep?.classList.toggle('d-none', isReview);
    reviewStep?.classList.toggle('d-none', !isReview);
    reviewButton?.classList.toggle('d-none', isReview);
    backButton?.classList.toggle('d-none', !isReview);
    confirmButton?.classList.toggle('d-none', !isReview || changes.length === 0);
    noChangesAlert?.classList.toggle('d-none', changes.length > 0);

    if (isReview) {
        renderReviewList(reviewList, changes);
    }
};

export const initCorrectCustomerDetailsDialog = (root) => {
    const form = root?.querySelector('[data-correct-customer-details-dialog]');

    if (!form) {
        return;
    }

    const reviewButton = form.querySelector('[data-correct-customer-details-review]');
    const backButton = form.querySelector('[data-correct-customer-details-back]');

    reviewButton?.addEventListener('click', () => {
        if (!form.reportValidity()) {
            return;
        }

        setStep(form, 'review');
    });

    backButton?.addEventListener('click', () => {
        setStep(form, 'edit');
    });
};
