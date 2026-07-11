import {
    animateDialogStep,
    buildFieldChanges,
    correctionDialogChangeOptions,
    initChangeDetection,
    initReasonCounter,
    renderReviewCards,
    renderReviewVerificationSource,
    renderValidationBannerHtml,
    VALIDATION_MESSAGES,
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

const renderValidationBanner = (element, type, message, detail = null) => {
    if (!(element instanceof HTMLElement)) {
        return;
    }

    if (!type) {
        element.innerHTML = '';
        return;
    }

    element.innerHTML = renderValidationBannerHtml({ type, message, detail });
};

const renderLiveValidation = (form, preview) => {
    const container = form.querySelector('[data-correct-serial-live-validation]');
    const validationBanner = form.querySelector('[data-correct-serial-live-validation-banner]');
    const duplicateBanner = form.querySelector('[data-correct-serial-live-duplicate-banner]');
    const normalized = form.querySelector('[data-correct-serial-live-normalized]');

    if (!(container instanceof HTMLElement)) {
        return;
    }

    if (!preview || preview.normalized_serial === '') {
        container.classList.add('d-none');
        renderValidationBanner(validationBanner, null);
        renderValidationBanner(duplicateBanner, null);
        return;
    }

    container.classList.remove('d-none');
    container.classList.remove('c360-dialog-validation-stack--expand');
    void container.offsetWidth;
    container.classList.add('c360-dialog-validation-stack--expand');
    renderValidationBanner(
        validationBanner,
        preview.severity,
        VALIDATION_MESSAGES[preview.severity] ?? 'Validation status',
        preview.reason || null,
    );
    renderValidationBanner(
        duplicateBanner,
        preview.duplicate ? 'duplicate-conflict' : 'duplicate-clear',
        preview.duplicate ? 'Already assigned' : 'Available',
        preview.duplicate
            ? (preview.duplicate_order_id
                ? `Used by order ${preview.duplicate_order_id}`
                : 'Used by another order')
            : 'No duplicate detected',
    );

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
    const validationBanner = form.querySelector('[data-correct-serial-review-validation-banner]');
    const duplicateBanner = form.querySelector('[data-correct-serial-review-duplicate-banner]');

    if (!(container instanceof HTMLElement)) {
        return;
    }

    if (!preview || preview.normalized_serial === '') {
        container.classList.add('d-none');
        renderValidationBanner(validationBanner, null);
        renderValidationBanner(duplicateBanner, null);
        return;
    }

    container.classList.remove('d-none');
    container.classList.remove('c360-dialog-validation-stack--expand');
    void container.offsetWidth;
    container.classList.add('c360-dialog-validation-stack--expand');
    renderValidationBanner(
        validationBanner,
        preview.severity,
        VALIDATION_MESSAGES[preview.severity] ?? 'Validation status',
        preview.reason || null,
    );
    renderValidationBanner(
        duplicateBanner,
        preview.duplicate ? 'duplicate-conflict' : 'duplicate-clear',
        preview.duplicate ? 'Already assigned' : 'Available',
        preview.duplicate
            ? (preview.duplicate_order_id
                ? `Used by order ${preview.duplicate_order_id}`
                : 'Used by another order')
            : 'No duplicate detected',
    );
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
    const activeStep = isReview ? reviewStep : editStep;

    editStep?.classList.toggle('d-none', isReview);
    reviewStep?.classList.toggle('d-none', !isReview);
    reviewButton?.classList.toggle('d-none', isReview);
    backButton?.classList.toggle('d-none', !isReview);
    confirmButton?.classList.toggle('d-none', !isReview || changes.length === 0);
    noChangesAlert?.classList.toggle('d-none', changes.length > 0);

    if (isReview) {
        renderReviewCards(reviewList, changes);
        renderReviewReason(form);
        renderReviewVerificationSource(form, {
            sectionSelector: '[data-correct-serial-number-review-source]',
            textSelector: '[data-correct-serial-number-review-source-text]',
        });
        renderReviewValidation(form, preview);
    }

    animateDialogStep(activeStep);
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

    initReasonCounter(form);

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
        ...correctionDialogChangeOptions,
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
