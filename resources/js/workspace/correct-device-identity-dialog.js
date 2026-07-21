import {
    animateDialogStep,
    initReasonCounter,
    renderReviewCards,
    renderReviewVerificationSource,
    renderValidationBannerHtml,
    VALIDATION_MESSAGES,
} from './c360-dialog';
import { csrfToken } from './http';

const DEBOUNCE_MS = 350;

let previewRequestId = 0;

const normalizeSerialInput = (value) => value.trim().toUpperCase();

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

const getDeviceModelSelect = (form) => form.querySelector('[name="device_model_id"]');

const getSerialInput = (form) => form.querySelector('[data-correct-device-identity-serial-input]');

const getSelectedModelName = (form) => {
    const select = getDeviceModelSelect(form);

    if (!(select instanceof HTMLSelectElement)) {
        return '—';
    }

    return select.selectedOptions[0]?.textContent?.trim() || '—';
};

const buildIdentityChanges = (form) => {
    const select = getDeviceModelSelect(form);
    const serialInput = getSerialInput(form);
    const changes = [];

    if (select instanceof HTMLSelectElement) {
        const originalId = (form.dataset.originalDeviceModelId ?? '').trim();
        const originalName = (form.dataset.originalDeviceModelName ?? '').trim();
        const nextId = select.value.trim();
        const nextName = select.selectedOptions[0]?.textContent?.trim() ?? '';

        if (originalId !== nextId) {
            changes.push({
                label: 'Device Model',
                current: originalName || '—',
                next: nextName || '—',
            });
        }
    }

    if (serialInput instanceof HTMLInputElement) {
        const original = (form.dataset.originalSerialNumber ?? '').trim().toUpperCase();
        const next = normalizeSerialInput(serialInput.value);

        if (original !== next) {
            changes.push({
                label: 'Serial Number',
                current: original || '—',
                next: next || '—',
            });
        }
    }

    return changes;
};

const refreshChangeState = (form) => {
    const changes = buildIdentityChanges(form);
    const reviewButton = form.querySelector('[data-correct-device-identity-review]');
    const changeStatus = form.querySelector('[data-c360-change-status]');

    if (reviewButton instanceof HTMLButtonElement) {
        reviewButton.disabled = changes.length === 0;
    }

    if (changeStatus instanceof HTMLElement) {
        changeStatus.textContent = changes.length === 0 ? 'No changes' : 'Changes detected';
    }

    return changes;
};

const renderLiveValidation = (form, preview) => {
    const container = form.querySelector('[data-correct-device-identity-live-validation]');
    const validationBanner = form.querySelector('[data-correct-device-identity-live-validation-banner]');
    const duplicateBanner = form.querySelector('[data-correct-device-identity-live-duplicate-banner]');
    const normalized = form.querySelector('[data-correct-device-identity-live-normalized]');

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
    const container = form.querySelector('[data-correct-device-identity-review-validation]');
    const validationBanner = form.querySelector('[data-correct-device-identity-review-validation-banner]');
    const duplicateBanner = form.querySelector('[data-correct-device-identity-review-duplicate-banner]');

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

const renderReviewReason = (form) => {
    const reasonSection = form.querySelector('[data-correct-device-identity-review-reason]');
    const reasonText = form.querySelector('[data-correct-device-identity-review-reason-text]');
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

const fetchPreview = async (form) => {
    const validationUrl = form.dataset.correctDeviceIdentityValidationUrl;
    const select = getDeviceModelSelect(form);
    const serialInput = getSerialInput(form);

    if (!validationUrl || !(select instanceof HTMLSelectElement) || !(serialInput instanceof HTMLInputElement)) {
        return null;
    }

    const serialNumber = normalizeSerialInput(serialInput.value);
    const deviceModelId = select.value.trim();

    if (serialNumber === '' || deviceModelId === '') {
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
            body: JSON.stringify({
                device_model_id: Number(deviceModelId),
                serial_number: serialNumber,
            }),
        });

        if (!response.ok || requestId !== previewRequestId) {
            return null;
        }

        return await response.json();
    } catch {
        return null;
    }
};

const setMismatchContent = (form, preview) => {
    const detected = form.querySelector('[data-correct-device-identity-mismatch-detected]');
    const selected = form.querySelector('[data-correct-device-identity-mismatch-selected]');

    if (detected instanceof HTMLElement) {
        detected.textContent = preview?.detection?.detected_device_model_name || 'another device model';
    }

    if (selected instanceof HTMLElement) {
        selected.textContent = getSelectedModelName(form);
    }
};

const setStep = (form, step, preview = null) => {
    const editStep = form.querySelector('[data-correct-device-identity-step="edit"]');
    const mismatchStep = form.querySelector('[data-correct-device-identity-step="mismatch"]');
    const reviewStep = form.querySelector('[data-correct-device-identity-step="review"]');
    const reviewButton = form.querySelector('[data-correct-device-identity-review]');
    const backButton = form.querySelector('[data-correct-device-identity-back]');
    const keepEditingButton = form.querySelector('[data-correct-device-identity-keep-editing]');
    const switchModelButton = form.querySelector('[data-correct-device-identity-switch-model]');
    const confirmButton = form.querySelector('[data-correct-device-identity-confirm]');
    const noChangesAlert = form.querySelector('[data-correct-device-identity-no-changes]');
    const reviewList = form.querySelector('[data-correct-device-identity-review-list]');

    const isReview = step === 'review';
    const isMismatch = step === 'mismatch';
    const changes = buildIdentityChanges(form);
    const activeStep = isReview ? reviewStep : (isMismatch ? mismatchStep : editStep);

    editStep?.classList.toggle('d-none', isReview || isMismatch);
    mismatchStep?.classList.toggle('d-none', !isMismatch);
    reviewStep?.classList.toggle('d-none', !isReview);
    reviewButton?.classList.toggle('d-none', isReview || isMismatch);
    backButton?.classList.toggle('d-none', !isReview);
    keepEditingButton?.classList.toggle('d-none', !isMismatch);
    switchModelButton?.classList.toggle('d-none', !isMismatch);
    confirmButton?.classList.toggle('d-none', !isReview || changes.length === 0);
    noChangesAlert?.classList.toggle('d-none', changes.length > 0);

    if (isMismatch) {
        setMismatchContent(form, preview);
    }

    if (isReview) {
        renderReviewCards(reviewList, changes);
        renderReviewReason(form);
        renderReviewVerificationSource(form, {
            sectionSelector: '[data-correct-device-identity-review-source]',
            textSelector: '[data-correct-device-identity-review-source-text]',
        });
        renderReviewValidation(form, preview);
    }

    animateDialogStep(activeStep);
};

const applyDetectedModel = (form, preview) => {
    const select = getDeviceModelSelect(form);
    const confirmSwitch = form.querySelector('[data-correct-device-identity-confirm-switch]');
    const detectedId = preview?.detection?.detected_device_model_id;

    if (select instanceof HTMLSelectElement && detectedId) {
        select.value = String(detectedId);
        select.dispatchEvent(new Event('change', { bubbles: true }));
    }

    if (confirmSwitch instanceof HTMLInputElement) {
        confirmSwitch.value = '1';
    }
};

export const initCorrectDeviceIdentityDialog = (root) => {
    const form = root?.querySelector('[data-correct-device-identity-dialog]');

    if (!form) {
        return;
    }

    const reviewButton = form.querySelector('[data-correct-device-identity-review]');
    const backButton = form.querySelector('[data-correct-device-identity-back]');
    const keepEditingButton = form.querySelector('[data-correct-device-identity-keep-editing]');
    const switchModelButton = form.querySelector('[data-correct-device-identity-switch-model]');
    const serialInput = getSerialInput(form);
    const modelSelect = getDeviceModelSelect(form);

    let latestPreview = null;
    let debounceTimer = null;

    initReasonCounter(form);
    refreshChangeState(form);

    const refreshPreview = async () => {
        latestPreview = await fetchPreview(form);
        renderLiveValidation(form, latestPreview);
        return latestPreview;
    };

    serialInput?.addEventListener('input', () => {
        if (serialInput instanceof HTMLInputElement) {
            serialInput.value = normalizeSerialInput(serialInput.value);
            serialInput.classList.remove('is-invalid');
        }

        refreshChangeState(form);
        window.clearTimeout(debounceTimer);
        debounceTimer = window.setTimeout(() => {
            refreshPreview();
        }, DEBOUNCE_MS);
    });

    modelSelect?.addEventListener('change', () => {
        refreshChangeState(form);
        window.clearTimeout(debounceTimer);
        debounceTimer = window.setTimeout(() => {
            refreshPreview();
        }, DEBOUNCE_MS);
    });

    form.querySelector('[name="reason"]')?.addEventListener('input', () => {
        refreshChangeState(form);
    });

    reviewButton?.addEventListener('click', async () => {
        if (!form.reportValidity()) {
            return;
        }

        const preview = await refreshPreview();

        if (preview?.outcome === 'mismatch') {
            setStep(form, 'mismatch', preview);
            return;
        }

        setStep(form, 'review', preview);
    });

    backButton?.addEventListener('click', () => {
        setStep(form, 'edit', latestPreview);
    });

    keepEditingButton?.addEventListener('click', () => {
        const confirmSwitch = form.querySelector('[data-correct-device-identity-confirm-switch]');

        if (confirmSwitch instanceof HTMLInputElement) {
            confirmSwitch.value = '0';
        }

        setStep(form, 'edit', latestPreview);
    });

    switchModelButton?.addEventListener('click', async () => {
        applyDetectedModel(form, latestPreview);
        refreshChangeState(form);
        const preview = await refreshPreview();
        setStep(form, 'review', preview);
    });

    form.addEventListener('workspace:response', (event) => {
        const data = event.detail;

        if (data?.extensions?.model_mismatch) {
            const mismatchPreview = {
                detection: {
                    detected_device_model_name: data.extensions.model_mismatch.detected_device_model_name,
                    detected_device_model_id: data.extensions.model_mismatch.detected_device_model_id,
                },
            };

            setStep(form, 'mismatch', mismatchPreview);
        }
    });
};
