import {
    animateDialogStep,
    buildFieldChanges,
    correctionDialogChangeOptions,
    initChangeDetection,
    initReasonCounter,
    renderReviewCards,
    renderReviewVerificationSource,
} from './c360-dialog';

const FIELD_DEFINITIONS = [
    {
        key: 'customer_name',
        label: 'Customer Name',
        inputName: 'customer_name',
        originalDatasetKey: 'originalCustomerName',
    },
    {
        key: 'customer_phone',
        label: 'Mobile Number',
        inputName: 'customer_phone',
        originalDatasetKey: 'originalCustomerPhone',
    },
    {
        key: 'customer_email',
        label: 'Email Address',
        inputName: 'customer_email',
        originalDatasetKey: 'originalCustomerEmail',
    },
];

const renderReviewReason = (form) => {
    const reasonSection = form.querySelector('[data-correct-customer-details-review-reason]');
    const reasonText = form.querySelector('[data-correct-customer-details-review-reason-text]');
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
    const editStep = form.querySelector('[data-correct-customer-details-step="edit"]');
    const reviewStep = form.querySelector('[data-correct-customer-details-step="review"]');
    const reviewButton = form.querySelector('[data-correct-customer-details-review]');
    const backButton = form.querySelector('[data-correct-customer-details-back]');
    const confirmButton = form.querySelector('[data-correct-customer-details-confirm]');
    const noChangesAlert = form.querySelector('[data-correct-customer-details-no-changes]');
    const reviewList = form.querySelector('[data-correct-customer-details-review-list]');

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
            sectionSelector: '[data-correct-customer-details-review-source]',
            textSelector: '[data-correct-customer-details-review-source-text]',
        });
    }

    animateDialogStep(activeStep);
};

export const initCorrectCustomerDetailsDialog = (root) => {
    const form = root?.querySelector('[data-correct-customer-details-dialog]');

    if (!form) {
        return;
    }

    const reviewButton = form.querySelector('[data-correct-customer-details-review]');
    const backButton = form.querySelector('[data-correct-customer-details-back]');

    initReasonCounter(form);

    initChangeDetection(form, {
        fieldDefinitions: FIELD_DEFINITIONS,
        reviewButton,
        ...correctionDialogChangeOptions,
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
