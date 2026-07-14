import {
    animateDialogStep,
    correctionDialogChangeOptions,
    initReasonCounter,
    renderReviewCards,
    renderReviewVerificationSource,
} from './c360-dialog';

const buildDeviceModelChanges = (form) => {
    const select = form.querySelector('[name="device_model_id"]');

    if (!(select instanceof HTMLSelectElement)) {
        return [];
    }

    const originalId = (form.dataset.originalDeviceModelId ?? '').trim();
    const originalName = (form.dataset.originalDeviceModelName ?? '').trim();
    const nextId = select.value.trim();
    const nextName = select.selectedOptions[0]?.textContent?.trim() ?? '';

    if (originalId === nextId) {
        return [];
    }

    return [{
        label: 'Device Model',
        current: originalName || '—',
        next: nextName || '—',
    }];
};

const renderReviewReason = (form) => {
    const reasonSection = form.querySelector('[data-correct-device-model-review-reason]');
    const reasonText = form.querySelector('[data-correct-device-model-review-reason-text]');
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

const setStep = (form, step) => {
    const editStep = form.querySelector('[data-correct-device-model-step="edit"]');
    const reviewStep = form.querySelector('[data-correct-device-model-step="review"]');
    const reviewButton = form.querySelector('[data-correct-device-model-review]');
    const backButton = form.querySelector('[data-correct-device-model-back]');
    const confirmButton = form.querySelector('[data-correct-device-model-confirm]');
    const noChangesAlert = form.querySelector('[data-correct-device-model-no-changes]');
    const reviewList = form.querySelector('[data-correct-device-model-review-list]');

    const isReview = step === 'review';
    const changes = buildDeviceModelChanges(form);
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
            sectionSelector: '[data-correct-device-model-review-source]',
            textSelector: '[data-correct-device-model-review-source-text]',
        });
    }

    animateDialogStep(activeStep);
};

const refreshChangeState = (form) => {
    const changes = buildDeviceModelChanges(form);
    const reviewButton = form.querySelector('[data-correct-device-model-review]');
    const changeStatus = form.querySelector('[data-c360-change-status]');

    if (reviewButton instanceof HTMLButtonElement) {
        reviewButton.disabled = changes.length === 0;
    }

    if (changeStatus instanceof HTMLElement) {
        changeStatus.textContent = changes.length === 0 ? 'No changes' : 'Changes detected';
    }

    return changes;
};

export const initCorrectDeviceModelDialog = (root) => {
    const form = root?.querySelector('[data-correct-device-model-dialog]');

    if (!form) {
        return;
    }

    const reviewButton = form.querySelector('[data-correct-device-model-review]');
    const backButton = form.querySelector('[data-correct-device-model-back]');
    const select = form.querySelector('[name="device_model_id"]');

    initReasonCounter(form);
    refreshChangeState(form);

    select?.addEventListener('change', () => {
        refreshChangeState(form);
    });

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
