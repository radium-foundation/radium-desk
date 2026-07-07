import * as bootstrap from 'bootstrap';
import { csrfToken } from './workspace/http';

export const formatIntakePreviewValue = (value) => {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (Array.isArray(value)) {
        return value.join(', ');
    }

    if (typeof value === 'object') {
        return JSON.stringify(value);
    }

    return String(value);
};

export const isLegacyOneClickEligible = (intake) => (
    Boolean(
        intake?.requires_confirmation
        && intake?.legacy_preview
        && intake?.legacy_preview_complete === true,
    )
);

export const resolveIntakeOutcome = (intake) => {
    if (intake?.classification === 'new_contact') {
        return 'new_contact';
    }

    if (intake?.requires_confirmation && intake?.legacy_preview) {
        if (isLegacyOneClickEligible(intake)) {
            return 'legacy_confirm';
        }

        return 'legacy_preview';
    }

    return 'matches';
};

export const buildLegacyPreviewSummaryHtml = (preview) => {
    const fields = [
        ['Order ID', preview.order_id],
        ['Customer name', preview.customer_name],
        ['Mobile', preview.mobile],
        ['Product / model', preview.product_model],
        ['Serial number', preview.serial_number],
    ];

    return `
        <dl class="dashboard-legacy-preview-card__fields mb-0">
            ${fields.map(([label, value]) => `
                <dt>${label}</dt>
                <dd>${formatIntakePreviewValue(value)}</dd>
            `).join('')}
        </dl>
    `;
};

const extractValidationMessage = (data) => {
    if (data?.errors && typeof data.errors === 'object') {
        const firstError = Object.values(data.errors)
            .flat()
            .find((value) => typeof value === 'string' && value !== '');

        if (firstError) {
            return firstError;
        }
    }

    if (typeof data?.message === 'string' && data.message !== '') {
        return data.message;
    }

    return 'Unable to create service request.';
};

const parseJsonResponse = async (response) => {
    const contentType = response.headers.get('content-type') ?? '';

    if (!contentType.includes('application/json')) {
        return null;
    }

    try {
        return await response.json();
    } catch {
        return null;
    }
};

const legacyCreateErrorMessage = (response) => {
    if (response.status === 419) {
        return 'Session expired. Refresh and try again.';
    }

    return 'Unable to create service request.';
};

let listenersWired = false;
let pendingLegacyConfirm = null;
let legacyConfirmContext = {};

const getLegacyConfirmModal = () => document.getElementById('legacySearchConfirmModal');

const createLegacyServiceRequest = async (
    intake,
    legacyConfirmModal,
    {
        source,
        notes = '',
        highPriority = false,
        submitButton = null,
    } = {},
) => {
    if (submitButton?.disabled) {
        return null;
    }

    const createUrl = intake?.create_url ?? '/service-requests/quick';
    const preview = intake?.legacy_preview ?? {};
    const parsedQuery = intake?.parsed_query ?? {};
    const legacyConfirmError = legacyConfirmModal?.querySelector('#legacy_search_confirm_error');

    if (!source || !preview.order_id) {
        legacyConfirmContext.showToast?.('Unable to create service request.', 'danger');

        return null;
    }

    const originalButtonHtml = submitButton?.innerHTML ?? 'Create Service Request';

    if (submitButton) {
        submitButton.disabled = true;
        submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Creating…';
    }

    if (legacyConfirmError) {
        legacyConfirmError.textContent = '';
        legacyConfirmError.classList.add('d-none');
    }

    try {
        const body = new FormData();
        body.append('action', 'legacy_import');
        body.append('legacy_order_id', preview.order_id);
        body.append('source', source);
        body.append('notes', notes.trim());

        if (highPriority) {
            body.append('high_priority', '1');
        }

        if (parsedQuery.phone) {
            body.append('phone', parsedQuery.phone);
        }

        const response = await fetch(createUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body,
        });

        const data = await parseJsonResponse(response);

        if (data === null) {
            legacyConfirmContext.showToast?.(legacyCreateErrorMessage(response), 'danger');

            return null;
        }

        if (!response.ok) {
            const message = extractValidationMessage(data);

            if (legacyConfirmError) {
                legacyConfirmError.textContent = message;
                legacyConfirmError.classList.remove('d-none');
            }

            legacyConfirmContext.showToast?.(message, 'danger');

            return null;
        }

        legacyConfirmContext.showToast?.(data.message ?? `Service Case ${data.display_reference} created`);

        return data;
    } catch {
        legacyConfirmContext.showToast?.('Unable to create service request.', 'danger');

        return null;
    } finally {
        if (submitButton) {
            submitButton.disabled = false;
            submitButton.innerHTML = originalButtonHtml;
        }
    }
};

const wireLegacySearchConfirmListeners = () => {
    if (listenersWired) {
        return;
    }

    listenersWired = true;

    document.addEventListener('click', async (event) => {
        const submitButton = event.target.closest('[data-legacy-search-confirm-submit]');

        if (!submitButton || !pendingLegacyConfirm) {
            return;
        }

        const legacyConfirmModal = getLegacyConfirmModal();
        const legacyConfirmError = legacyConfirmModal?.querySelector('#legacy_search_confirm_error');
        const source = legacyConfirmModal?.querySelector('#legacy_search_confirm_source')?.value ?? '';
        const notes = legacyConfirmModal?.querySelector('#legacy_search_confirm_notes')?.value?.trim() ?? '';

        if (!notes) {
            if (legacyConfirmError) {
                legacyConfirmError.textContent = 'Comment / issue description is required.';
                legacyConfirmError.classList.remove('d-none');
            }

            return;
        }

        if (!source) {
            if (legacyConfirmError) {
                legacyConfirmError.textContent = 'Select a source before continuing.';
                legacyConfirmError.classList.remove('d-none');
            }

            return;
        }

        const highPriority = legacyConfirmModal?.querySelector('#legacy_search_confirm_high_priority')?.checked ?? false;

        const data = await createLegacyServiceRequest(
            pendingLegacyConfirm.intake,
            legacyConfirmModal,
            {
                source,
                notes,
                highPriority,
                submitButton,
            },
        );

        if (!data) {
            return;
        }

        const confirmContext = pendingLegacyConfirm;
        bootstrap.Modal.getInstance(legacyConfirmModal)?.hide();
        pendingLegacyConfirm = null;

        if (confirmContext.onSuccess) {
            await confirmContext.onSuccess(data);

            return;
        }

        await legacyConfirmContext.onLegacyCreateSuccess?.(data, confirmContext);
    });

    document.addEventListener('hidden.bs.modal', (event) => {
        if (event.target?.id !== 'legacySearchConfirmModal') {
            return;
        }

        if (pendingLegacyConfirm?.onCancel) {
            pendingLegacyConfirm.onCancel();
        }

        pendingLegacyConfirm = null;
    });
};

export const initLegacySearchConfirmModal = ({
    showToast = null,
    onLegacyCreateSuccess = null,
} = {}) => {
    legacyConfirmContext = {
        showToast: showToast ?? legacyConfirmContext.showToast,
        onLegacyCreateSuccess: onLegacyCreateSuccess ?? legacyConfirmContext.onLegacyCreateSuccess,
    };

    wireLegacySearchConfirmListeners();
};

export const openLegacySearchConfirmModal = (intake, query = '', callbacks = {}) => {
    const legacyConfirmModal = document.getElementById('legacySearchConfirmModal');

    if (!legacyConfirmModal) {
        legacyConfirmContext.showToast?.('Unable to open confirmation dialog.', 'danger');

        return false;
    }

    pendingLegacyConfirm = {
        intake,
        query,
        onSuccess: callbacks.onSuccess ?? null,
        onCancel: callbacks.onCancel ?? null,
    };

    const sourceSelect = legacyConfirmModal.querySelector('#legacy_search_confirm_source');
    const notesField = legacyConfirmModal.querySelector('#legacy_search_confirm_notes');
    const highPriorityField = legacyConfirmModal.querySelector('#legacy_search_confirm_high_priority');
    const legacyConfirmError = legacyConfirmModal.querySelector('#legacy_search_confirm_error');

    if (sourceSelect) {
        sourceSelect.value = intake?.default_source ?? '';
    }

    if (notesField) {
        notesField.value = '';
    }

    if (highPriorityField) {
        highPriorityField.checked = false;
    }

    if (legacyConfirmError) {
        legacyConfirmError.textContent = '';
        legacyConfirmError.classList.add('d-none');
    }

    const setLegacyConfirmField = (selector, value) => {
        const field = legacyConfirmModal.querySelector(selector);

        if (field) {
            field.textContent = formatIntakePreviewValue(value);
        }
    };

    const preview = intake?.legacy_preview ?? {};
    setLegacyConfirmField('[data-legacy-confirm-order-id]', preview.order_id);
    setLegacyConfirmField('[data-legacy-confirm-customer-name]', preview.customer_name);
    setLegacyConfirmField('[data-legacy-confirm-mobile]', preview.mobile);
    setLegacyConfirmField('[data-legacy-confirm-email]', preview.email);
    setLegacyConfirmField('[data-legacy-confirm-product-model]', preview.product_model);
    setLegacyConfirmField('[data-legacy-confirm-serial-number]', preview.serial_number);

    bootstrap.Modal.getOrCreateInstance(legacyConfirmModal).show();

    return true;
};
