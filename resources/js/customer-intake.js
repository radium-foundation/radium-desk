import * as bootstrap from 'bootstrap';
import { csrfToken } from './workspace/http';
import { getWorkspaceSession } from './workspace';
import {
    initLegacySearchConfirmModal,
    openLegacySearchConfirmModal,
    resolveIntakeOutcome,
} from './intake-search-flow';

const showStep = (modal, stepId) => {
    modal.querySelectorAll('.intake-step').forEach((step) => {
        step.classList.toggle('d-none', step.id !== stepId);
    });
};

const updateModalTitle = (modal, title) => {
    const label = modal.querySelector('#quickCreateModalLabel');

    if (label) {
        label.textContent = title;
    }
};

const preserveSearchValues = (form) => ({
    phone: form.querySelector('#intake_phone')?.value ?? '',
    orderId: form.querySelector('#intake_order_id')?.value ?? '',
    serialNumber: form.querySelector('#intake_serial_number')?.value ?? '',
    email: form.dataset.intakeSearchedEmail ?? '',
});

const restoreSearchValues = (form, { phone, orderId, serialNumber }) => {
    const phoneField = form.querySelector('#intake_phone');
    const orderField = form.querySelector('#intake_order_id');
    const serialField = form.querySelector('#intake_serial_number');

    if (phoneField) {
        phoneField.value = phone;
    }

    if (orderField) {
        orderField.value = orderId;
    }

    if (serialField) {
        serialField.value = serialNumber;
    }
};

const resolveSearchedContactValues = (form, parsedQuery = null) => ({
    phone: parsedQuery?.phone ?? form.querySelector('#intake_phone')?.value.trim() ?? '',
    orderId: parsedQuery?.order_id ?? form.querySelector('#intake_order_id')?.value.trim() ?? '',
    serialNumber: parsedQuery?.serial_number ?? form.querySelector('#intake_serial_number')?.value.trim() ?? '',
    email: parsedQuery?.email ?? form.dataset.intakeSearchedEmail ?? '',
});

export const syncNewContactSearchedDisplay = (form, parsedQuery = null) => {
    const values = resolveSearchedContactValues(form, parsedQuery);
    const container = form.querySelector('[data-intake-searched-contact]');
    const hiddenPhone = form.querySelector('#intake_new_contact_phone');
    const hiddenSerial = form.querySelector('#intake_new_contact_serial_number');
    const fieldMap = {
        phone: values.phone,
        email: values.email,
        order_id: values.orderId,
        serial_number: values.serialNumber,
    };

    if (hiddenPhone) {
        hiddenPhone.value = values.phone;
    }

    if (hiddenSerial) {
        hiddenSerial.value = values.serialNumber;
    }

    if (values.email) {
        form.dataset.intakeSearchedEmail = values.email;
    } else {
        delete form.dataset.intakeSearchedEmail;
    }

    if (!container) {
        return;
    }

    let hasVisibleField = false;

    Object.entries(fieldMap).forEach(([field, value]) => {
        const row = container.querySelector(`[data-intake-searched-field="${field}"]`);
        const valueElement = container.querySelector(`[data-intake-searched-value="${field}"]`);
        const trimmedValue = typeof value === 'string' ? value.trim() : '';

        if (!row || !valueElement) {
            return;
        }

        if (trimmedValue === '') {
            row.classList.add('d-none');
            valueElement.textContent = '';

            return;
        }

        row.classList.remove('d-none');
        valueElement.textContent = trimmedValue;
        hasVisibleField = true;
    });

    container.classList.toggle('d-none', !hasVisibleField);
};

const clearIntakeValidationState = (modal, form) => {
    const feedback = modal.querySelector('#intake-search-feedback');

    if (feedback) {
        feedback.className = 'alert d-none mt-3 mb-0 py-2 small';
        feedback.textContent = '';
    }

    form.querySelectorAll('.is-invalid').forEach((field) => {
        field.classList.remove('is-invalid');
    });

    form.querySelectorAll('.invalid-feedback').forEach((element) => {
        if (element.hasAttribute('data-intake-intent-error')) {
            element.textContent = '';
            element.classList.remove('d-block');

            return;
        }

        element.textContent = '';
        element.classList.remove('d-block');
    });
};

const returnToSearchStep = (modal, form) => {
    if (!form) {
        return;
    }

    const searchValues = preserveSearchValues(form);

    clearIntakeValidationState(modal, form);

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

    form.querySelectorAll('input[name="intent"]').forEach((input) => {
        input.checked = false;
    });

    const hiddenPhone = form.querySelector('#intake_new_contact_phone');
    const hiddenSerial = form.querySelector('#intake_new_contact_serial_number');

    if (hiddenPhone) {
        hiddenPhone.value = '';
    }

    if (hiddenSerial) {
        hiddenSerial.value = '';
    }

    delete form.dataset.intakeSearchedEmail;
    syncNewContactSearchedDisplay(form);

    const searchButton = modal.querySelector('#intake-search-button');
    const submitButton = modal.querySelector('#intake-submit-button');
    searchButton?.classList.remove('d-none');
    submitButton?.classList.add('d-none');

    showStep(modal, 'intake-step-search');
    updateModalTitle(modal, 'Find Customer');
    restoreSearchValues(form, searchValues);
};

export const advanceQuickCreateToNewContact = (modalElement, form, parsedQuery = null) => {
    const actionField = form.querySelector('#intake_action');
    const matchedOrderField = form.querySelector('#intake_matched_order_id');
    const legacyOrderField = form.querySelector('#intake_legacy_order_id');
    const searchButton = modalElement.querySelector('#intake-search-button');
    const submitButton = modalElement.querySelector('#intake-submit-button');
    const feedback = modalElement.querySelector('#intake-search-feedback');

    if (actionField) {
        actionField.value = 'new_contact';
    }

    if (matchedOrderField) {
        matchedOrderField.value = '';
    }

    if (legacyOrderField) {
        legacyOrderField.value = '';
    }

    if (feedback) {
        feedback.classList.add('d-none');
        feedback.textContent = '';
    }

    syncNewContactSearchedDisplay(form, parsedQuery);

    showStep(modalElement, 'intake-step-new-contact');
    modalElement.querySelector('#intake-step-details')?.classList.remove('d-none');
    updateModalTitle(modalElement, 'New Service Request');
    searchButton?.classList.add('d-none');
    submitButton?.classList.remove('d-none');
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
    updateModalTitle(modal, 'Find Customer');

    clearIntakeValidationState(modal, form);
};

const formatAmcDetails = (value) => {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (typeof value === 'string') {
        const trimmed = value.trim();

        if (trimmed.startsWith('{') || trimmed.startsWith('[')) {
            try {
                return formatAmcDetails(JSON.parse(trimmed));
            } catch {
                return trimmed;
            }
        }

        return trimmed;
    }

    if (Array.isArray(value)) {
        return value.map((item) => formatAmcDetails(item)).join(', ');
    }

    if (typeof value === 'object') {
        if (typeof value.service_name === 'string' && value.service_name.trim() !== '') {
            return value.service_name;
        }

        return Object.values(value)
            .filter((item) => item !== null && item !== undefined && item !== '')
            .map((item) => formatAmcDetails(item))
            .join(', ');
    }

    return String(value);
};

const formatPreviewValue = (value) => {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (Array.isArray(value)) {
        return value.map((item) => {
            if (typeof item === 'object' && item !== null) {
                return JSON.stringify(item);
            }

            return String(item);
        }).join(', ');
    }

    if (typeof value === 'object') {
        return JSON.stringify(value);
    }

    return String(value);
};

const renderLegacyPreview = (modal, form, data) => {
    const preview = data.legacy_preview;

    if (!preview) {
        return;
    }

    const message = modal.querySelector('#intake-legacy-preview-message');
    const fields = modal.querySelector('#intake-legacy-preview-fields');
    const searchButton = modal.querySelector('#intake-search-button');
    const submitButton = modal.querySelector('#intake-submit-button');
    const actionField = form.querySelector('#intake_action');
    const legacyOrderField = form.querySelector('#intake_legacy_order_id');
    const matchedOrderField = form.querySelector('#intake_matched_order_id');

    if (message) {
        message.textContent = data.legacy_preview_message ?? 'Legacy order found. Create service case?';
    }

    if (fields) {
        const previewFields = [
            ['Order ID', preview.order_id],
            ['Customer name', preview.customer_name],
            ['Mobile', preview.mobile],
            ['Email', preview.email],
            ['Product / model', preview.product_model],
            ['Serial number', preview.serial_number],
            ['GST number', preview.gst_number],
            ['Invoice number', preview.invoice_number],
            ['Purchase / activation year', preview.purchase_year],
            ['RD service history', preview.service_history],
            ['AMC status', preview.amc_status],
            ['AMC year', preview.amc_year],
            ['AMC details', preview.amc_details_display ?? preview.amc_details],
            ['Order date', preview.legacy_order_date],
            ['Order status', preview.legacy_order_status],
        ];

        fields.innerHTML = previewFields.map(([label, value]) => {
            const formattedValue = label === 'AMC details'
                ? formatAmcDetails(value)
                : formatPreviewValue(value);

            return `
            <dt class="col-sm-4 text-muted">${label}</dt>
            <dd class="col-sm-8 mb-2">${formattedValue}</dd>
        `;
        }).join('');
    }

    if (actionField) {
        actionField.value = 'legacy_import';
    }

    if (legacyOrderField) {
        legacyOrderField.value = preview.order_id ?? '';
    }

    if (matchedOrderField) {
        matchedOrderField.value = '';
    }

    showStep(modal, 'intake-step-legacy-preview');
    searchButton?.classList.add('d-none');
    submitButton?.classList.add('d-none');

    modal.querySelector('#intake-legacy-confirm-button')?.replaceWith(
        modal.querySelector('#intake-legacy-confirm-button').cloneNode(true),
    );

    modal.querySelector('#intake-legacy-confirm-button')?.addEventListener('click', () => {
        showStep(modal, 'intake-step-details');
        searchButton?.classList.add('d-none');
        submitButton?.classList.remove('d-none');
    });
};

const renderExistingRecordCard = (modal, form, match) => {
    const list = modal.querySelector('#intake-matches-list');
    const classificationLabel = modal.querySelector('#intake-classification-label');
    const searchButton = modal.querySelector('#intake-search-button');
    const existingCase = match.existing_case;

    if (!list || !classificationLabel || !existingCase) {
        return;
    }

    classificationLabel.textContent = 'Existing Radium Desk record found.';
    list.innerHTML = '';

    const item = document.createElement('div');
    item.className = 'list-group-item intake-existing-record-card';
    item.innerHTML = `
        <dl class="row small mb-3 intake-existing-record-card__fields">
            <dt class="col-sm-3 text-muted">Order</dt>
            <dd class="col-sm-9 mb-2 fw-semibold">${match.order_id}</dd>
            <dt class="col-sm-3 text-muted">Case</dt>
            <dd class="col-sm-9 mb-2 fw-semibold">${existingCase.display_reference}</dd>
            <dt class="col-sm-3 text-muted">Status</dt>
            <dd class="col-sm-9 mb-0">${existingCase.status_label}</dd>
        </dl>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-sm btn-primary" data-intake-open-customer-360="true">
                Open Customer 360
            </button>
            ${existingCase.can_reopen ? `
                <button type="button" class="btn btn-sm btn-outline-primary" data-intake-reopen-case="true">
                    Reopen Case
                </button>
            ` : ''}
            ${existingCase.is_closed ? '' : `
                <button type="button" class="btn btn-sm btn-outline-secondary" data-intake-select-match="true">
                    Create Service Case
                </button>
            `}
        </div>
        <div class="text-danger small mt-2 d-none" data-intake-existing-record-error></div>
    `;

    item.querySelector('[data-intake-open-customer-360]')?.addEventListener('click', () => {
        bootstrap.Modal.getInstance(modal)?.hide();
        document.dispatchEvent(new CustomEvent('customer360:open', {
            detail: {
                incidentId: existingCase.incident_id,
                referenceLabel: existingCase.display_reference,
            },
        }));
    });

    item.querySelector('[data-intake-reopen-case]')?.addEventListener('click', async () => {
        const errorElement = item.querySelector('[data-intake-existing-record-error]');
        const reopenButton = item.querySelector('[data-intake-reopen-case]');

        if (!existingCase.reopen_url) {
            return;
        }

        if (errorElement) {
            errorElement.classList.add('d-none');
            errorElement.textContent = '';
        }

        if (reopenButton) {
            reopenButton.disabled = true;
        }

        try {
            const response = await fetch(existingCase.reopen_url, {
                method: 'PATCH',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    workspace_context: existingCase.reopen_workspace_context,
                    action_type: 'reopen',
                    body: 'Reopened from Quick Create search.',
                }),
            });

            const data = await response.json();

            if (!response.ok) {
                if (errorElement) {
                    errorElement.textContent = data.message ?? 'Unable to reopen service case.';
                    errorElement.classList.remove('d-none');
                }

                return;
            }

            bootstrap.Modal.getInstance(modal)?.hide();
            document.dispatchEvent(new CustomEvent('customer360:open', {
                detail: {
                    incidentId: existingCase.incident_id,
                    referenceLabel: existingCase.display_reference,
                },
            }));
        } catch {
            if (errorElement) {
                errorElement.textContent = 'Unable to reopen service case.';
                errorElement.classList.remove('d-none');
            }
        } finally {
            if (reopenButton) {
                reopenButton.disabled = false;
            }
        }
    });

    item.querySelector('[data-intake-select-match]')?.addEventListener('click', () => {
        const actionField = form.querySelector('#intake_action');
        const matchedOrderField = form.querySelector('#intake_matched_order_id');
        const legacyOrderField = form.querySelector('#intake_legacy_order_id');

        actionField.value = 'existing_order';
        matchedOrderField.value = String(match.id);
        legacyOrderField.value = '';

        showStep(modal, 'intake-step-details');
        searchButton?.classList.add('d-none');
        modal.querySelector('#intake-submit-button')?.classList.remove('d-none');
    });

    list.appendChild(item);
    showStep(modal, 'intake-step-results');
    searchButton?.classList.add('d-none');
    modal.querySelector('#intake-submit-button')?.classList.add('d-none');
};

const renderMatches = (modal, form, data) => {
    const list = modal.querySelector('#intake-matches-list');
    const classificationLabel = modal.querySelector('#intake-classification-label');
    const searchButton = modal.querySelector('#intake-search-button');
    const submitButton = modal.querySelector('#intake-submit-button');

    if (!list || !classificationLabel) {
        return;
    }

    if (data.matches.length === 1 && data.matches[0].existing_case) {
        renderExistingRecordCard(modal, form, data.matches[0]);
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
            feedback.textContent = 'Enter a phone number, order ID, or serial number to continue.';
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

        const outcome = resolveIntakeOutcome(data);

        if (outcome === 'new_contact') {
            advanceQuickCreateToNewContact(modal, form, data.parsed_query ?? null);
            return;
        }

        if (outcome === 'legacy_confirm') {
            bootstrap.Modal.getInstance(modal)?.hide();
            openLegacySearchConfirmModal(data, '', {
                onCancel: () => {
                    bootstrap.Modal.getOrCreateInstance(modal).show();
                },
                onSuccess: async (result) => {
                    bootstrap.Modal.getInstance(modal)?.hide();
                    document.dispatchEvent(new CustomEvent('customer360:open', {
                        detail: {
                            incidentId: result.incident_id,
                            referenceLabel: result.display_reference,
                        },
                    }));
                    document.dispatchEvent(new CustomEvent('customer360:refresh', {
                        detail: { incidentId: result.incident_id },
                    }));
                },
            });
            return;
        }

        if (outcome === 'legacy_preview') {
            renderLegacyPreview(modal, form, data);
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

export const initCustomerIntake = ({ showToast = null } = {}) => {
    initLegacySearchConfirmModal({ showToast });

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
            returnToSearchStep(modalElement, form);
        });
    });

    form?.addEventListener('submit', (event) => {
        const actionField = form.querySelector('#intake_action');
        const action = actionField?.value ?? '';
        const notesField = form.querySelector('#intake_notes');
        const notes = notesField?.value.trim() ?? '';

        if (notes === '') {
            event.preventDefault();
            notesField?.classList.add('is-invalid');

            const existingFeedback = notesField?.parentElement?.querySelector('.invalid-feedback');

            if (existingFeedback) {
                existingFeedback.textContent = 'Comment / issue description is required.';
            }
        }

        if (action === 'new_contact') {
            const customerNameField = form.querySelector('#intake_customer_name');
            const customerName = customerNameField?.value.trim() ?? '';
            const selectedIntent = form.querySelector('input[name="intent"]:checked');
            const intentError = form.querySelector('[data-intake-intent-error]');

            if (customerName === '') {
                event.preventDefault();
                customerNameField?.classList.add('is-invalid');

                const nameFeedback = customerNameField?.parentElement?.querySelector('.invalid-feedback');

                if (nameFeedback) {
                    nameFeedback.textContent = 'Customer name is required.';
                }
            }

            if (!selectedIntent) {
                event.preventDefault();

                form.querySelectorAll('input[name="intent"]').forEach((input) => {
                    input.classList.add('is-invalid');
                });

                if (intentError) {
                    intentError.textContent = 'Select the customer intent before continuing.';
                    intentError.classList.add('d-block');
                }
            }
        }
    });

    form?.querySelectorAll('input[name="intent"]').forEach((input) => {
        input.addEventListener('change', () => {
            input.classList.remove('is-invalid');
            form.querySelectorAll('input[name="intent"]').forEach((intentInput) => {
                intentInput.classList.remove('is-invalid');
            });

            const intentError = form.querySelector('[data-intake-intent-error]');

            if (intentError) {
                intentError.textContent = '';
                intentError.classList.remove('d-block');
            }

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

const configureLegacyVerificationModal = (modalElement, mode = 'customer') => {
    const customerPanel = modalElement.querySelector('#legacy-verification-customer-panel');
    const importedPanel = modalElement.querySelector('#legacy-verification-imported-panel');
    const title = modalElement.querySelector('#legacyVerificationModalLabel');
    const isImported = mode === 'imported';

    customerPanel?.classList.toggle('d-none', isImported);
    importedPanel?.classList.toggle('d-none', ! isImported);

    if (title) {
        title.textContent = isImported
            ? 'Legacy Imported Order Verification'
            : 'Legacy Customer Verification';
    }
};

const activeLegacyVerificationCheckbox = (modalElement, mode = 'customer') => {
    if (mode === 'imported') {
        return modalElement.querySelector('#legacy_import_fulfillment_confirmed');
    }

    return modalElement.querySelector('#legacy_verification_confirmed');
};

const resetLegacyVerificationModal = (modalElement) => {
    const checkbox = modalElement?.querySelector('#legacy_verification_confirmed');
    const importedCheckbox = modalElement?.querySelector('#legacy_import_fulfillment_confirmed');
    const confirmButton = modalElement?.querySelector('#legacy-verification-confirm-button');
    const error = modalElement?.querySelector('#legacy-verification-error');

    if (checkbox) {
        checkbox.checked = false;
    }

    if (importedCheckbox) {
        importedCheckbox.checked = false;
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
    const confirmButton = modalElement.querySelector('#legacy-verification-confirm-button');
    const error = modalElement.querySelector('#legacy-verification-error');
    const customerCheckbox = modalElement.querySelector('#legacy_verification_confirmed');
    const importedCheckbox = modalElement.querySelector('#legacy_import_fulfillment_confirmed');

    const syncConfirmButton = () => {
        const mode = pendingLegacyVerification?.verificationMode ?? 'customer';
        const checkbox = activeLegacyVerificationCheckbox(modalElement, mode);

        if (confirmButton) {
            confirmButton.disabled = !checkbox?.checked;
        }
    };

    customerCheckbox?.addEventListener('change', syncConfirmButton);
    importedCheckbox?.addEventListener('change', syncConfirmButton);

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
        resetLegacyVerificationModal(modalElement);
    });

    return {
        requestVerification: ({ verificationUrl, verificationMode = 'customer', onVerified }) => new Promise((resolve, reject) => {
            pendingLegacyVerification = {
                verificationUrl,
                verificationMode,
                onVerified: async () => {
                    try {
                        await onVerified?.();
                        resolve(true);
                    } catch (callbackError) {
                        reject(callbackError);
                    }
                },
            };

            configureLegacyVerificationModal(modalElement, verificationMode);
            syncConfirmButton();
            modal.show();
        }),
    };
};

export const setPendingLegacyVerification = (context) => {
    pendingLegacyVerification = context;
};

export const guardServiceReferenceAssignment = async ({
    requiresLegacyVerification,
    legacyVerificationUrl,
    legacyVerificationModal,
    legacyVerificationMode = 'customer',
    onProceed,
}) => {
    if (requiresLegacyVerification && legacyVerificationUrl && legacyVerificationModal) {
        return legacyVerificationModal.requestVerification({
            verificationUrl: legacyVerificationUrl,
            verificationMode: legacyVerificationMode,
            onVerified: onProceed,
        });
    }

    return onProceed();
};
