import {
    buildFieldChanges,
    initChangeDetection,
    renderReviewCards,
} from './c360-dialog';
import { csrfToken } from './http';

const FIELD_DEFINITIONS = [
    {
        key: 'serial_number',
        label: 'Serial Number',
        inputName: 'serial_number',
        originalDatasetKey: 'originalSerialNumber',
    },
];

const VALIDATION_LABELS = {
    pass: '✓ Verified',
    warning: '⚠ Needs review',
    fail: '✕ Validation failed',
};

const DEBOUNCE_MS = 350;

let previewRequestId = 0;

const normalizeSerialInput = (value) => value.trim().toUpperCase();

const renderReviewReason = (form) => {
    const reasonSection = form.querySelector('[data-correct-serial-number-review-reason]');
    const reasonText = form.querySelector('[data-correct-serial-number-review-reason-text]');
    const reasonInput = form.querySelector('[name="reason"]');

    if (!(reasonSection instanceof HTMLElement) || !(reasonText instanceof HTMLElement)) {
        return;
    }

    const reason = reasonInput instanceof HTMLTextAreaElement
        ? reasonInput.value.trim()
        : '';

    if (reason === '') {
        reasonSection.classList.add('d-none');
        reasonText.textContent = '';
        return;
    }

    reasonSection.classList.remove('d-none');
    reasonText.textContent = reason;
};

const applyValidationBadge = (element, severity, reasonElement, reason) => {
    if (!(element instanceof HTMLElement)) {
        return;
    }

    element.classList.remove(
        'c360-dialog-serial-validation-badge--pass',
        'c360-dialog-serial-validation-badge--warning',
        'c360-dialog-serial-validation-badge--fail',
    );

    if (!severity) {
        element.textContent = '—';
        return;
    }

    element.classList.add(`c360-dialog-serial-validation-badge--${severity}`);
    element.textContent = VALIDATION_LABELS[severity] ?? severity;

    if (reasonElement instanceof HTMLElement) {
        if (reason) {
            reasonElement.textContent = reason;
            reasonElement.classList.remove('d-none');
        } else {
            reasonElement.textContent = '';
            reasonElement.classList.add('d-none');
        }
    }
};

const applyDuplicateBadge = (element, duplicate, duplicateOrderId) => {
    if (!(element instanceof HTMLElement)) {
        return;
    }

    element.classList.remove(
        'c360-dialog-serial-duplicate-badge--clear',
        'c360-dialog-serial-duplicate-badge--conflict',
    );

    if (duplicate) {
        element.classList.add('c360-dialog-serial-duplicate-badge--conflict');
        element.textContent = duplicateOrderId
            ? `Used by order ${duplicateOrderId}`
            : 'Used by another order';
        return;
    }

    element.classList.add('c360-dialog-serial-duplicate-badge--clear');
    element.textContent = 'No duplicate detected';
};

const renderLiveValidation = (form, preview) => {
    const container = form.querySelector('[data-correct-serial-live-validation]');
    const badge = form.querySelector('[data-correct-serial-live-validation-badge]');
    const reason = form.querySelector('[data-correct-serial-live-validation-reason]');
    const duplicateBadge = form.querySelector('[data-correct-serial-live-duplicate-badge]');
    const normalized = form.querySelector('[data-correct-serial-live-normalized]');

    if (!(container instanceof HTMLElement)) {
        return;
    }

    if (!preview || preview.normalized_serial === '') {
        container.classList.add('d-none');
        return;
    }

    container.classList.remove('d-none');
    applyValidationBadge(badge, preview.severity, reason, preview.reason);
    applyDuplicateBadge(duplicateBadge, preview.duplicate, preview.duplicate_order_id);

    if (normalized instanceof HTMLElement) {
        if (preview.corrected && preview.normalized_serial) {
            normalized.textContent = `Will be saved as ${preview.normalized_serial}`;
            normalized.classList.remove('d-none');
        } else {
            normalized.textContent = '';
            normalized.classList.add('d-none');
        }
    }
};

const renderReviewValidation = (form, preview) => {
    const container = form.querySelector('[data-correct-serial-review-validation]');
    const badge = form.querySelector('[data-correct-serial-review-validation-badge]');
    const reason = form.querySelector('[data-correct-serial-review-validation-reason]');
    const duplicateBadge = form.querySelector('[data-correct-serial-review-duplicate-badge]');

    if (!(container instanceof HTMLElement)) {
        return;
    }

    if (!preview || preview.normalized_serial === '') {
        container.classList.add('d-none');
        return;
    }

    container.classList.remove('d-none');
    applyValidationBadge(badge, preview.severity, reason, preview.reason);
    applyDuplicateBadge(duplicateBadge, preview.duplicate, preview.duplicate_order_id);
};

const fetchPreview = async (form, serialNumber) => {
    const validationUrl = form.dataset.correctSerialValidationUrl;

    if (!validationUrl || serialNumber === '') {
        return null;
    }

    const requestId = ++previewRequestId;

    try {
        const response = await fetch(validationUrl, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ serial_number: serialNumber }),
        });

        if (!response.ok || requestId !== previewRequestId) {
            return null;
        }

        return await response.json();
    } catch {
        return null;
    }
};

const setStep = (form, step, preview = null) => {
    const editStep = form.querySelector('[data-correct-serial-number-step="edit"]');
    const reviewStep = form.querySelector('[data-correct-serial-number-step="review"]');
    const reviewButton = form.querySelector('[data-correct-serial-number-review]');
    const backButton = form.querySelector('[data-correct-serial-number-back]');
    const confirmButton = form.querySelector('[data-correct-serial-number-confirm]');
    const noChangesAlert = form.querySelector('[data-correct-serial-number-no-changes]');
    const reviewList = form.querySelector('[data-correct-serial-number-review-list]');

    const isReview = step === 'review';
    const changes = buildFieldChanges(form, FIELD_DEFINITIONS);

    editStep?.classList.toggle('d-none', isReview);
    reviewStep?.classList.toggle('d-none', !isReview);
    reviewButton?.classList.toggle('d-none', isReview);
    backButton?.classList.toggle('d-none', !isReview);
    confirmButton?.classList.toggle('d-none', !isReview || changes.length === 0);
    noChangesAlert?.classList.toggle('d-none', changes.length > 0);

    if (isReview) {
        renderReviewCards(reviewList, changes);
        renderReviewReason(form);
        renderReviewValidation(form, preview);
    }
};

export const initCorrectSerialNumberDialog = (root) => {
    const form = root?.querySelector('[data-correct-serial-number-dialog]');

    if (!form) {
        return;
    }

    const reviewButton = form.querySelector('[data-correct-serial-number-review]');
    const backButton = form.querySelector('[data-correct-serial-number-back]');
    const serialInput = form.querySelector('[data-correct-serial-number-input]');

    let latestPreview = null;
    let debounceTimer = null;

    const refreshPreview = async () => {
        if (!(serialInput instanceof HTMLInputElement)) {
            return;
        }

        const normalized = normalizeSerialInput(serialInput.value);

        if (normalized === '') {
            latestPreview = null;
            renderLiveValidation(form, null);
            return;
        }

        latestPreview = await fetchPreview(form, normalized);
        renderLiveValidation(form, latestPreview);
    };

    initChangeDetection(form, {
        fieldDefinitions: FIELD_DEFINITIONS,
        reviewButton,
    });

    if (serialInput instanceof HTMLInputElement) {
        serialInput.addEventListener('input', () => {
            serialInput.value = normalizeSerialInput(serialInput.value);
            serialInput.classList.remove('is-invalid');

            window.clearTimeout(debounceTimer);
            debounceTimer = window.setTimeout(() => {
                refreshPreview();
            }, DEBOUNCE_MS);
        });
    }

    reviewButton?.addEventListener('click', async () => {
        if (!form.reportValidity()) {
            return;
        }

        await refreshPreview();
        setStep(form, 'review', latestPreview);
    });

    backButton?.addEventListener('click', () => {
        setStep(form, 'edit');
    });
};
