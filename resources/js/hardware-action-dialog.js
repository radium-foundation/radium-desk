import * as bootstrap from 'bootstrap';
import { csrfToken, workspaceFetch, workspaceFetchHeaders } from './workspace/http';

const modalHost = () => document.querySelector('[data-workspace-modal-host]');
const modalContent = () => document.querySelector('[data-workspace-modal-content]');

const showError = (root, message) => {
    if (!root) {
        return;
    }

    let alert = root.querySelector('[data-hardware-action-error]');
    if (!alert) {
        alert = document.createElement('div');
        alert.className = 'alert alert-danger py-2 px-3 small mb-3';
        alert.setAttribute('data-hardware-action-error', 'true');
        alert.setAttribute('role', 'alert');
        const body = root.querySelector('.c360-dialog-body');
        body?.prepend(alert);
    }

    alert.textContent = message;
};

const bindSerialPickers = (root) => {
    root.querySelectorAll('[data-hardware-serial-picker]').forEach((picker) => {
        const query = picker.querySelector('[data-hardware-serial-query]');
        const results = picker.querySelector('[data-hardware-serial-results]');
        const selected = picker.querySelector('[data-hardware-serial-selected]');
        const itemId = picker.dataset.itemId;
        const searchUrl = picker.dataset.searchUrl;
        if (!query || !results || !selected || !itemId || !searchUrl) {
            return;
        }

        let timer = null;
        const search = async () => {
            const q = query.value.trim();
            if (q.length < 2) {
                results.textContent = '';
                return;
            }

            const url = new URL(searchUrl, window.location.origin);
            url.searchParams.set('commerce_order_item_id', itemId);
            url.searchParams.set('q', q);
            const response = await workspaceFetch(url.toString(), {
                headers: workspaceFetchHeaders('application/json'),
            });
            const payload = await response.json();
            const serials = payload.serials ?? [];
            results.replaceChildren();
            serials.forEach((serial) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'btn btn-sm btn-outline-secondary me-1 mb-1';
                button.textContent = serial.serial_number ?? serial.serial ?? serial;
                button.addEventListener('click', () => {
                    const value = serial.serial_number ?? serial.serial ?? serial;
                    if (selected.querySelector(`input[value="${CSS.escape(value)}"]`)) {
                        return;
                    }
                    const item = document.createElement('li');
                    item.innerHTML = `<input type="hidden" name="serials[${itemId}][]" value="${value}">${value}`;
                    selected.append(item);
                });
                results.append(button);
            });
            if (serials.length === 0) {
                results.textContent = 'No available serials.';
            }
        };

        query.addEventListener('input', () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(() => {
                void search();
            }, 200);
        });
    });
};

const closeModal = () => {
    const host = modalHost();
    if (!host || !window.bootstrap) {
        return;
    }

    bootstrap.Modal.getOrCreateInstance(host).hide();
};

let showToast = () => {};

const handleSuccess = (payload) => {
    closeModal();

    if (payload.refresh_customer360 && payload.incident_id) {
        document.dispatchEvent(new CustomEvent('customer360:refresh', {
            detail: { incidentId: payload.incident_id },
        }));
    }

    if (payload.status) {
        showToast(payload.status);
    }

    const next = payload.next_action;
    const dialogUrl = payload.action_dialog_url;
    if (payload.mutating && dialogUrl && next && next !== 'Ready' && next !== 'View' && next !== 'Completed') {
        window.setTimeout(() => {
            void openDialog(dialogUrl);
        }, 350);
    }
};

const submitForm = async (form) => {
    const root = form.closest('[data-hardware-action-dialog-root]') ?? form;
    const submit = form.querySelector('[data-hardware-action-submit]');
    submit?.setAttribute('disabled', 'disabled');

    try {
        const body = new FormData(form);
        const response = await workspaceFetch(form.action, {
            method: 'POST',
            headers: {
                ...workspaceFetchHeaders('application/json'),
                'X-CSRF-TOKEN': csrfToken(),
            },
            body,
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            const message = payload.message
                ?? payload.errors?.[Object.keys(payload.errors ?? {})[0]]?.[0]
                ?? 'This hardware action could not be completed.';
            showError(root, message);
            return;
        }

        handleSuccess(payload);
    } catch (error) {
        showError(root, error?.message ?? 'This hardware action could not be completed.');
    } finally {
        submit?.removeAttribute('disabled');
    }
};

export const openDialog = async (url) => {
    const host = modalHost();
    const content = modalContent();
    if (!host || !content) {
        return;
    }

    const response = await workspaceFetch(url, {
        headers: workspaceFetchHeaders('text/html'),
    });
    if (!response.ok) {
        return;
    }

    content.innerHTML = await response.text();
    const root = content.querySelector('[data-hardware-action-dialog-root]');
    bindSerialPickers(content);
    content.querySelectorAll('[data-hardware-action-form]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            void submitForm(form);
        });
    });
    bootstrap.Modal.getOrCreateInstance(host).show();
    return root;
};

export const initHardwareActionDialog = (options = {}) => {
    showToast = options.showToast ?? showToast;

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-hardware-action-dialog]');
        if (!trigger) {
            return;
        }

        const url = trigger.getAttribute('data-hardware-action-dialog');
        if (!url) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        void openDialog(url);
    });
};
