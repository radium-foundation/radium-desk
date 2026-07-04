import * as bootstrap from 'bootstrap';
import { csrfToken } from './workspace/http';
import { getWorkspaceSession } from './workspace';

const showStep = (modal, stepId) => {
    modal.querySelectorAll('.intake-step').forEach((step) => {
        step.classList.toggle('d-none', step.id !== stepId);
    });
};

const resetIntakeForm = (modal, form) => {
    if (!form) {
        return;
    }

    form.reset();

    const actionField = form.querySelector('#intake_action');
    if (actionField) {
        actionField.value = 'new_contact';
    }

    const matchedOrderField = form.querySelector('#intake_matched_order_id');
    if (matchedOrderField) {
        matchedOrderField.value = '';
    }

    const legacyOrderField = form.querySelector('#intake_legacy_order_id');
    if (legacyOrderField) {
        legacyOrderField.value = '';
    }

    const searchButton = modal.querySelector('#intake-search-button');
    const submitButton = modal.querySelector('#intake-submit-button');
    searchButton?.classList.remove('d-none');
    submitButton?.classList.add('d-none');

    showStep(modal, 'intake-step-search');

    const feedback = modal.querySelector('#intake-search-feedback');
    if (feedback) {
        feedback.classList.add('d-none');
        feedback.textContent = '';
    }

    form.querySelectorAll('.is-invalid').forEach((field) => {
        field.classList.remove('is-invalid');
    });
};

const renderMatches = (modal, form, data) => {
    const list = modal.querySelector('#intake-matches-list');
    const classificationLabel = modal.querySelector('#intake-classification-label');
    const searchButton = modal.querySelector('#intake-search-button');
    const submitButton = modal.querySelector('#intake-submit-button');

    if (!list || !classificationLabel) {
        return;
    }

    classificationLabel.textContent = data.classification_label ?? '';
    list.innerHTML = '';

    data.matches.forEach((match) => {
        const item = document.createElement('div');
        item.className = 'list-group-item';
        item.innerHTML = `
            <div class="d-flex justify-content-between align-items-start gap-2">
                <div>
                    <div class="fw-semibold">${match.order_id}</div>
                    <div class="small text-muted">
                        ${match.customer_phone ? `Phone: ${match.customer_phone}` : 'Phone: —'}
                        · Serial: ${match.serial_number ?? '—'}
                    </div>
                </div>
                <span class="badge text-bg-light">${match.identity_type.replaceAll('_', ' ')}</span>
            </div>
            <div class="d-flex gap-2 mt-2">
                ${match.id > 0 ? `<a class="btn btn-sm btn-outline-primary" href="/orders/${match.id}">Open Order</a>` : ''}
                <button type="button" class="btn btn-sm btn-primary" data-intake-select-match="true">Create Service Case</button>
            </div>
        `;

        item.querySelector('[data-intake-select-match]')?.addEventListener('click', () => {
            const actionField = form.querySelector('#intake_action');
            const matchedOrderField = form.querySelector('#intake_matched_order_id');
            const legacyOrderField = form.querySelector('#intake_legacy_order_id');

            if (match.id > 0) {
                actionField.value = 'existing_order';
                matchedOrderField.value = String(match.id);
                legacyOrderField.value = '';
            } else {
                actionField.value = 'legacy_radiumbox';
                legacyOrderField.value = match.order_id;
                matchedOrderField.value = '';
            }

            showStep(modal, 'intake-step-details');
            searchButton?.classList.add('d-none');
            submitButton?.classList.remove('d-none');
        });

        list.appendChild(item);
    });

    showStep(modal, 'intake-step-results');
    searchButton?.classList.add('d-none');
    submitButton?.classList.add('d-none');
};

const searchCustomer = async (modal, form) => {
    const searchUrl = modal.dataset.intakeSearchUrl;
    const feedback = modal.querySelector('#intake-search-feedback');
    const searchButton = modal.querySelector('#intake-search-button');

    if (!searchUrl) {
        return;
    }

    const phone = form.querySelector('#intake_phone')?.value.trim() ?? '';
    const orderId = form.querySelector('#intake_order_id')?.value.trim() ?? '';
    const serialNumber = form.querySelector('#intake_serial_number')?.value.trim() ?? '';

    if (phone === '' && orderId === '' && serialNumber === '') {
        if (feedback) {
            feedback.className = 'alert alert-danger mt-3 mb-0 py-2 small';
            feedback.textContent = 'Enter a phone number, order ID, or serial number to search.';
            feedback.classList.remove('d-none');
        }

        return;
    }

    if (searchButton) {
        searchButton.disabled = true;
    }

    try {
        const response = await fetch(searchUrl, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                phone,
                order_id: orderId,
                serial_number: serialNumber,
            }),
        });

        const data = await response.json();

        if (!response.ok) {
            const message = data.errors?.search?.[0]
                ?? data.message
                ?? 'Unable to search customers.';

            if (feedback) {
                feedback.className = 'alert alert-danger mt-3 mb-0 py-2 small';
                feedback.textContent = message;
                feedback.classList.remove('d-none');
            }

            return;
        }

        if (feedback) {
            feedback.classList.add('d-none');
        }

        if (data.classification === 'new_contact') {
            const actionField = form.querySelector('#intake_action');
            if (actionField) {
                actionField.value = 'new_contact';
            }

            showStep(modal, 'intake-step-new-contact');
            modal.querySelector('#intake-step-details')?.classList.remove('d-none');
            modal.querySelector('#intake-search-button')?.classList.add('d-none');
            modal.querySelector('#intake-submit-button')?.classList.remove('d-none');
            return;
        }

        renderMatches(modal, form, data);
    } catch (error) {
        if (feedback) {
            feedback.className = 'alert alert-danger mt-3 mb-0 py-2 small';
            feedback.textContent = 'Unable to search customers.';
            feedback.classList.remove('d-none');
        }
    } finally {
        if (searchButton) {
            searchButton.disabled = false;
        }
    }
};

export const initCustomerIntake = () => {
    const modalElement = document.getElementById('quickCreateModal');

    if (!modalElement) {
        return;
    }

    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
    const form = modalElement.querySelector('#customerIntakeForm');

    modalElement.addEventListener('show.bs.modal', () => {
        getWorkspaceSession().acquire('quick-create');

        if (modalElement.dataset.resetOnShow === 'true') {
            resetIntakeForm(modalElement, form);
        }
    });

    modalElement.addEventListener('hidden.bs.modal', () => {
        getWorkspaceSession().release('quick-create');
    });

    modalElement.querySelector('#intake-search-button')?.addEventListener('click', () => {
        searchCustomer(modalElement, form);
    });

    modalElement.querySelectorAll('[data-intake-back]').forEach((button) => {
        button.addEventListener('click', () => {
            resetIntakeForm(modalElement, form);
        });
    });

    form?.querySelectorAll('input[name="intent"]').forEach((input) => {
        input.addEventListener('change', () => {
            showStep(modalElement, 'intake-step-new-contact');
            modalElement.querySelector('#intake-step-details')?.classList.remove('d-none');
        });
    });

    const shouldReopen = modalElement.dataset.showOnLoad === 'true'
        || document.getElementById('dashboard-page')?.dataset.reopenQuickCreate === 'true';

    if (shouldReopen) {
        window.setTimeout(() => {
            modal.show();
        }, 0);
    }
};

let pendingLegacyVerification = null;

const resetLegacyVerificationModal = () => {
    const checkbox = document.getElementById('legacy_verification_confirmed');
    const confirmButton = document.getElementById('legacy-verification-confirm-button');
    const error = document.getElementById('legacy-verification-error');

    if (checkbox) {
        checkbox.checked = false;
    }

    if (confirmButton) {
        confirmButton.disabled = true;
    }

    if (error) {
        error.textContent = '';
    }

    pendingLegacyVerification = null;
};

export const initLegacyVerificationModal = () => {
    const modalElement = document.getElementById('legacyVerificationModal');

    if (!modalElement) {
        return null;
    }

    const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
    const checkbox = modalElement.querySelector('#legacy_verification_confirmed');
    const confirmButton = modalElement.querySelector('#legacy-verification-confirm-button');
    const error = modalElement.querySelector('#legacy-verification-error');

    checkbox?.addEventListener('change', () => {
        if (confirmButton) {
            confirmButton.disabled = !checkbox.checked;
        }
    });

    confirmButton?.addEventListener('click', async () => {
        if (!pendingLegacyVerification?.verificationUrl) {
            return;
        }

        confirmButton.disabled = true;

        try {
            const response = await fetch(pendingLegacyVerification.verificationUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ confirmed: true }),
            });

            const data = await response.json();

            if (!response.ok) {
                if (error) {
                    error.textContent = data.message ?? 'Unable to confirm legacy verification.';
                }

                confirmButton.disabled = false;
                return;
            }

            modal.hide();
            await pendingLegacyVerification.onVerified?.();
            resetLegacyVerificationModal();
        } catch (verificationError) {
            if (error) {
                error.textContent = 'Unable to confirm legacy verification.';
            }

            confirmButton.disabled = false;
        }
    });

    modalElement.addEventListener('hidden.bs.modal', () => {
        resetLegacyVerificationModal();
    });

    return {
        requestVerification: ({ verificationUrl, onVerified }) => new Promise((resolve, reject) => {
            pendingLegacyVerification = {
                verificationUrl,
                onVerified: async () => {
                    try {
                        await onVerified?.();
                        resolve(true);
                    } catch (callbackError) {
                        reject(callbackError);
                    }
                },
            };

            modal.show();
        }),
    };
};

export const setPendingLegacyVerification = (context) => {
    pendingLegacyVerification = context;
};
