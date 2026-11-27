import * as bootstrap from 'bootstrap';
import { csrfToken, workspaceFetch } from './workspace/http';
import { WorkspaceRequestError } from './workspace/error-handler';
import { showAppToast } from './core/toast';

const formatInr = (value) => {
    const amount = Number(value);
    if (!Number.isFinite(amount)) {
        return '—';
    }

    return `₹${amount.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
};

const supportOrderIdFromSelection = (workspace) => {
    const checked = Array.from(workspace.querySelectorAll('[data-hardware-select]:checked'));

    if (checked.length !== 1) {
        return null;
    }

    const input = checked[0];
    const fromInput = input.getAttribute('data-hardware-support-order-id');
    if (fromInput) {
        return Number(fromInput);
    }

    const fromRow = input.closest('tr')?.getAttribute('data-hardware-support-order-id');

    return fromRow ? Number(fromRow) : null;
};

const setCancelButtonState = (button, { enabled, title }) => {
    if (!button) {
        return;
    }

    button.disabled = !enabled;
    if (title) {
        button.setAttribute('title', title);
    }
};

const FIELD_NAMES = [
    'cancellation_reason',
    'refund_preference',
    'operator_notes',
    'order_id',
    'fulfilment',
    'commerce_order',
    'requested_refund_amount',
    'idempotency_key',
];

const firstErrorMessage = (errors) => {
    if (!errors || typeof errors !== 'object') {
        return null;
    }

    for (const field of FIELD_NAMES) {
        const messages = errors[field];
        if (Array.isArray(messages) && messages[0]) {
            return String(messages[0]);
        }
        if (typeof messages === 'string' && messages !== '') {
            return messages;
        }
    }

    return null;
};

const parseJsonBody = async (response) => {
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

const requestIdFromResponse = (response) => response.headers.get('X-Request-Id')
    ?? response.headers.get('x-request-id')
    ?? null;

export const initHardwareDashboardPreshipCancel = (root = document) => {
    const workspace = root.querySelector('[data-hardware-workspace]');

    if (!workspace || workspace.dataset.hardwarePreshipCancelEnabled !== '1') {
        return;
    }

    const cancelButton = workspace.querySelector('[data-hardware-cancel-order]');
    const dialog = workspace.querySelector('[data-hardware-preship-cancel-dialog]');
    const form = workspace.querySelector('[data-hardware-preship-cancel-form]');
    const submitButton = workspace.querySelector('[data-hardware-preship-cancel-submit]');
    const idempotencyInput = workspace.querySelector('[data-hardware-preship-idempotency-key]');

    if (!cancelButton || !dialog || !form) {
        return;
    }

    const modalApi = bootstrap.Modal.getOrCreateInstance(dialog, {
        backdrop: true,
        keyboard: true,
        focus: true,
    });

    let preflightAbort = null;
    let preflightToken = 0;
    let activePreflight = null;
    let submitInFlight = false;
    let lastFocusedElement = null;

    const closeButtons = workspace.querySelectorAll('[data-hardware-preship-cancel-close]');

    const toggle = (element, visible) => {
        if (!element) {
            return;
        }

        element.classList.toggle('d-none', !visible);
        element.hidden = !visible;
    };

    const clearFieldErrors = () => {
        workspace.querySelectorAll('[data-hardware-preship-field-error]').forEach((node) => {
            node.textContent = '';
        });
        form.querySelectorAll('.is-invalid').forEach((input) => {
            input.classList.remove('is-invalid');
        });
    };

    const setFieldError = (field, message) => {
        if (!message) {
            return;
        }

        const node = workspace.querySelector(`[data-hardware-preship-field-error="${field}"]`);
        if (node) {
            node.textContent = message;
        }

        const control = form.querySelector(`[name="${field}"]`);
        if (control && control instanceof HTMLElement) {
            control.classList.add('is-invalid');
        }
    };

    const setSubmitError = (message) => {
        const submitError = workspace.querySelector('[data-hardware-preship-cancel-submit-error]');
        if (!submitError) {
            return;
        }

        if (message) {
            submitError.textContent = message;
            toggle(submitError, true);
        } else {
            submitError.textContent = '';
            toggle(submitError, false);
        }
    };

    const resetDialog = () => {
        activePreflight = null;
        form.reset();
        if (idempotencyInput) {
            idempotencyInput.value = '';
        }
        clearFieldErrors();
        setSubmitError('');
        toggle(form, false);
        toggle(workspace.querySelector('[data-hardware-preship-cancel-breakdown]'), false);
        toggle(workspace.querySelector('[data-hardware-preship-cancel-split-tender]'), false);
        toggle(workspace.querySelector('[data-hardware-preship-cancel-ineligible]'), false);
        toggle(workspace.querySelector('[data-hardware-preship-cancel-breakdown-error]'), false);
        if (submitButton) {
            submitButton.disabled = true;
        }
        submitInFlight = false;
    };

    const closeDialog = () => {
        resetDialog();
        modalApi.hide();
    };

    const openDialog = () => {
        lastFocusedElement = document.activeElement instanceof HTMLElement
            ? document.activeElement
            : cancelButton;
        modalApi.show();
    };

    dialog.addEventListener('shown.bs.modal', () => {
        const reason = form.querySelector('[data-hardware-preship-cancellation-reason]');
        if (reason instanceof HTMLElement) {
            reason.focus();
        }
    });

    dialog.addEventListener('hidden.bs.modal', () => {
        if (lastFocusedElement instanceof HTMLElement) {
            lastFocusedElement.focus();
        }
    });

    const applyPreflightToDialog = (payload) => {
        activePreflight = payload;

        const subtitle = workspace.querySelector('[data-hardware-preship-cancel-subtitle]');
        if (subtitle && payload.order?.order_id) {
            subtitle.textContent = `Order ${payload.order.order_id}. Phase 2 State A+B only.`;
        }

        const ineligible = workspace.querySelector('[data-hardware-preship-cancel-ineligible]');
        if (!payload.eligible || !payload.can_submit) {
            const code = payload.eligibility_code ? ` (${payload.eligibility_code})` : '';
            if (ineligible) {
                ineligible.textContent = `This order is not eligible for pre-shipment cancellation${code}.`;
            }
            toggle(ineligible, true);
            toggle(form, false);
            return;
        }

        toggle(ineligible, false);

        const breakdown = payload.payment_breakdown;
        if (breakdown) {
            workspace.querySelector('[data-hardware-preship-order-total]').textContent = formatInr(breakdown.order_total);
            workspace.querySelector('[data-hardware-preship-external-tender]').textContent = formatInr(breakdown.external_tender);
            workspace.querySelector('[data-hardware-preship-wallet-tender]').textContent = formatInr(breakdown.wallet_tender);
            workspace.querySelector('[data-hardware-preship-already-refunded]').textContent = formatInr(breakdown.already_refunded);
            workspace.querySelector('[data-hardware-preship-max-refundable]').textContent = formatInr(breakdown.maximum_refundable);
            toggle(workspace.querySelector('[data-hardware-preship-cancel-breakdown]'), true);
            toggle(workspace.querySelector('[data-hardware-preship-cancel-split-tender]'), Boolean(breakdown.split_tender));
        }

        if (payload.breakdown_error) {
            const node = workspace.querySelector('[data-hardware-preship-cancel-breakdown-error]');
            node.textContent = payload.breakdown_error;
            toggle(node, true);
            toggle(form, false);
            return;
        }

        if (idempotencyInput && payload.order?.id) {
            idempotencyInput.value = `psc-hw:${payload.order.id}:${Date.now()}`;
        }

        toggle(form, true);
        if (submitButton) {
            submitButton.disabled = false;
        }
    };

    const applyValidationErrors = (errors) => {
        if (!errors) {
            return;
        }

        Object.entries(errors).forEach(([field, messages]) => {
            const message = Array.isArray(messages) ? messages[0] : String(messages);
            if (FIELD_NAMES.includes(field)) {
                setFieldError(field, message);
            }
        });

        const topLevel = firstErrorMessage(errors);
        if (topLevel && !['cancellation_reason', 'refund_preference', 'operator_notes'].some((f) => errors[f])) {
            setSubmitError(topLevel);
        }
    };

    const messageForFailedResponse = (response, data) => {
        const requestId = requestIdFromResponse(response);
        const requestSuffix = requestId ? ` Reference: ${requestId}.` : '';

        if (response.status === 401) {
            return 'Your session has expired. Sign in again and retry.';
        }
        if (response.status === 403) {
            return data?.message ?? 'You are not authorized to submit this cancellation.';
        }
        if (response.status === 419) {
            return 'Your session or security token expired. Refresh the page and try again.';
        }
        if (response.status === 422) {
            return data?.message ?? firstErrorMessage(data?.errors) ?? 'Fix the highlighted fields and try again.';
        }
        if (response.status >= 500) {
            return `Cancellation could not be submitted due to a server error.${requestSuffix}`;
        }

        return data?.message ?? 'Cancellation could not be submitted.';
    };

    const runPreflight = async (supportOrderId) => {
        preflightAbort?.abort();
        preflightAbort = new AbortController();
        const token = ++preflightToken;

        setCancelButtonState(cancelButton, {
            enabled: false,
            title: 'Checking eligibility…',
        });

        try {
            const url = `/orders/${supportOrderId}/pre-shipment-cancellations/preflight`;
            const response = await fetch(url, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: preflightAbort.signal,
            });

            if (token !== preflightToken) {
                return;
            }

            if (response.status === 404) {
                setCancelButtonState(cancelButton, {
                    enabled: false,
                    title: 'Pre-shipment cancellation is not available.',
                });
                return;
            }

            if (response.status === 403) {
                setCancelButtonState(cancelButton, {
                    enabled: false,
                    title: 'You are not authorized to cancel orders.',
                });
                return;
            }

            const payload = await parseJsonBody(response) ?? {};

            if (!payload.eligible || !payload.can_submit) {
                const code = payload.eligibility_code ?? 'ineligible';
                setCancelButtonState(cancelButton, {
                    enabled: false,
                    title: `Selected order is not eligible (${code}).`,
                });
                return;
            }

            setCancelButtonState(cancelButton, {
                enabled: true,
                title: 'Cancel the selected order (pre-shipment)',
            });
        } catch (error) {
            if (error?.name === 'AbortError') {
                return;
            }

            setCancelButtonState(cancelButton, {
                enabled: false,
                title: 'Could not verify eligibility. Retry selection.',
            });
        }
    };

    const syncCancelButton = () => {
        const checked = workspace.querySelectorAll('[data-hardware-select]:checked');

        if (checked.length === 0) {
            setCancelButtonState(cancelButton, {
                enabled: false,
                title: 'Select exactly one eligible order to cancel',
            });
            return;
        }

        if (checked.length > 1) {
            setCancelButtonState(cancelButton, {
                enabled: false,
                title: 'Select only one order to cancel',
            });
            return;
        }

        const supportOrderId = supportOrderIdFromSelection(workspace);
        if (!supportOrderId) {
            setCancelButtonState(cancelButton, {
                enabled: false,
                title: 'Selected row has no linked Desk order',
            });
            return;
        }

        void runPreflight(supportOrderId);
    };

    workspace.addEventListener('hardware:selection-changed', syncCancelButton);

    cancelButton.addEventListener('click', async () => {
        const supportOrderId = supportOrderIdFromSelection(workspace);
        if (!supportOrderId) {
            return;
        }

        resetDialog();
        openDialog();

        try {
            const response = await fetch(`/orders/${supportOrderId}/pre-shipment-cancellations/preflight`, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                const ineligible = workspace.querySelector('[data-hardware-preship-cancel-ineligible]');
                ineligible.textContent = response.status === 403
                    ? 'You are not authorized to load cancellation details for this order.'
                    : 'Unable to load cancellation details. Close and try again.';
                toggle(ineligible, true);
                return;
            }

            const payload = await parseJsonBody(response);
            if (!payload) {
                const ineligible = workspace.querySelector('[data-hardware-preship-cancel-ineligible]');
                ineligible.textContent = 'Unable to load cancellation details. Close and try again.';
                toggle(ineligible, true);
                return;
            }

            applyPreflightToDialog(payload);
        } catch {
            const ineligible = workspace.querySelector('[data-hardware-preship-cancel-ineligible]');
            ineligible.textContent = 'Unable to load cancellation details. Close and try again.';
            toggle(ineligible, true);
        }
    });

    closeButtons.forEach((button) => {
        button.addEventListener('click', () => {
            if (!submitInFlight) {
                resetDialog();
            }
        });
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (!activePreflight?.store_url || !activePreflight?.can_submit || submitInFlight) {
            return;
        }

        submitInFlight = true;
        clearFieldErrors();
        setSubmitError('');

        if (submitButton) {
            submitButton.disabled = true;
        }

        const formData = new FormData(form);
        const refundPreference = formData.get('refund_preference');
        const body = {
            cancellation_reason: String(formData.get('cancellation_reason') ?? '').trim(),
            refund_preference: refundPreference ? String(refundPreference) : null,
            operator_notes: String(formData.get('operator_notes') ?? '').trim() || null,
            idempotency_key: String(formData.get('idempotency_key') ?? ''),
        };

        try {
            const response = await workspaceFetch(activePreflight.store_url, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(body),
            });

            const data = await parseJsonBody(response);

            if (!response.ok) {
                if (response.status === 422 && data?.errors) {
                    applyValidationErrors(data.errors);
                }
                setSubmitError(messageForFailedResponse(response, data));
                submitInFlight = false;
                if (submitButton) {
                    submitButton.disabled = false;
                }
                return;
            }

            closeDialog();
            showAppToast(data?.message ?? 'Order cancellation submitted.');

            workspace.querySelectorAll('[data-hardware-select]:checked').forEach((input) => {
                input.checked = false;
            });
            const selectAll = workspace.querySelector('[data-hardware-select-all]');
            if (selectAll) {
                selectAll.checked = false;
                selectAll.indeterminate = false;
            }

            workspace.dispatchEvent(new CustomEvent('hardware:selection-changed'));
            workspace.dispatchEvent(new CustomEvent('hardware:request-reload', { bubbles: true }));
        } catch (error) {
            if (error instanceof WorkspaceRequestError && error.kind === 'session') {
                setSubmitError(error.message);
            } else if (error instanceof WorkspaceRequestError && error.kind === 'network') {
                setSubmitError(error.message);
            } else {
                setSubmitError('Cancellation could not be submitted. Check your connection and try again.');
            }
            submitInFlight = false;
            if (submitButton) {
                submitButton.disabled = false;
            }
        }
    });

    syncCancelButton();
};