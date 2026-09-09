import * as bootstrap from 'bootstrap';
import { csrfToken, workspaceFetch, workspaceFetchHeaders } from './workspace/http';

export const SERIAL_ALLOCATE_MESSAGES = {
    mixedBranch: 'Selected serials belong to different branches. Use serials from one branch only.',
    duplicate: 'This serial is already selected.',
    incomplete: 'Select all required serials before allocating.',
    wrongProduct: 'This serial cannot be allocated to this product.',
    noEligible: 'No eligible serials are currently available for this product.',
    lineFull: 'This line already has the required number of serials.',
};

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

const escapeHtml = (value) => String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

const displayBranch = (code) => {
    const upper = String(code || '').toUpperCase();
    if (upper === 'DELHI-RETAIL' || upper === 'DELHI') {
        return 'Delhi';
    }
    if (upper === 'MUMBAI') {
        return 'Mumbai';
    }

    return code || '';
};

const serialValue = (serial) => String(serial?.serial_number ?? serial?.serial ?? serial ?? '').trim();

const collectPickers = (root) => Array.from(root.querySelectorAll('[data-hardware-serial-picker]')).map((el) => ({
    el,
    itemId: el.dataset.itemId,
    qty: Number(el.dataset.qty || '0'),
    searchUrl: el.dataset.searchUrl,
    query: el.querySelector('[data-hardware-serial-query]'),
    results: el.querySelector('[data-hardware-serial-results]'),
    selected: el.querySelector('[data-hardware-serial-selected]'),
    count: el.querySelector('[data-hardware-serial-count]'),
    lineError: el.querySelector('[data-hardware-serial-line-error]'),
    chosen: [],
    timer: null,
}));

const setLineError = (picker, message) => {
    if (!picker.lineError) {
        return;
    }

    picker.lineError.textContent = message || '';
    picker.lineError.classList.toggle('d-none', !message);
};

const setFormError = (root, message) => {
    const el = root.querySelector('[data-hardware-serial-client-error]');
    if (!el) {
        return;
    }

    el.textContent = message || '';
    el.classList.toggle('d-none', !message);
};

const uniqueBranches = (pickers) => {
    const codes = {};
    pickers.forEach((picker) => {
        picker.chosen.forEach((row) => {
            if (row.branch_code) {
                codes[row.branch_code] = true;
            }
        });
    });

    return Object.keys(codes);
};

const allChosen = (pickers) => pickers.flatMap((picker) => picker.chosen);

const selectionComplete = (pickers) => pickers.length > 0 && pickers.every((picker) => picker.chosen.length === picker.qty);

const isDuplicate = (pickers, serialNumber, exceptPicker = null) => {
    const needle = serialNumber.toUpperCase();

    return pickers.some((picker) => {
        if (exceptPicker && picker === exceptPicker) {
            return false;
        }

        return picker.chosen.some((row) => row.serial_number.toUpperCase() === needle);
    });
};

const renderSelected = (picker) => {
    if (!picker.selected) {
        return;
    }

    picker.selected.replaceChildren();
    picker.chosen.forEach((row) => {
        const item = document.createElement('li');
        item.className = 'd-flex justify-content-between align-items-center gap-2 mb-1';
        const branch = displayBranch(row.branch_code);
        item.innerHTML = `<span>✓ ${escapeHtml(row.serial_number)}${branch ? ` · ${escapeHtml(branch)}` : ''}</span>`
            + `<input type="hidden" name="serials[${escapeHtml(picker.itemId)}][]" value="${escapeHtml(row.serial_number)}">`;
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn btn-sm btn-link p-0';
        remove.setAttribute('data-hardware-serial-remove', row.serial_number);
        remove.textContent = 'Remove';
        remove.addEventListener('click', () => {
            picker.chosen = picker.chosen.filter((entry) => entry.serial_number !== row.serial_number);
            renderSelected(picker);
            picker.lastResults?.length && renderResults(picker, picker.lastResults);
            updateAllocateState(picker.root, picker.pickers);
        });
        item.append(remove);
        picker.selected.append(item);
    });

    if (picker.count) {
        picker.count.textContent = `Serials allocated: ${picker.chosen.length} / ${picker.qty}`;
    }
};

const renderResults = (picker, serials) => {
    picker.lastResults = serials;
    picker.results.replaceChildren();
    if (!serials.length) {
        picker.results.textContent = SERIAL_ALLOCATE_MESSAGES.noEligible;
        return;
    }

    serials.forEach((serial) => {
        const value = serialValue(serial);
        if (!value) {
            return;
        }

        const selected = picker.chosen.some((item) => item.serial_number === value);
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-sm btn-outline-secondary me-1 mb-1';
        button.textContent = value;
        if (selected) {
            button.classList.add('active');
        }
        button.addEventListener('click', () => {
            chooseSerial(picker, {
                serial_number: value,
                branch_code: serial.branch_code || '',
            });
        });
        picker.results.append(button);
    });
};

const chooseSerial = (picker, row) => {
    const pickers = picker.pickers;
    const root = picker.root;
    setLineError(picker, '');

    const alreadyHere = picker.chosen.some((item) => item.serial_number === row.serial_number);
    if (alreadyHere) {
        picker.chosen = picker.chosen.filter((item) => item.serial_number !== row.serial_number);
        renderSelected(picker);
        picker.lastResults?.length && renderResults(picker, picker.lastResults);
        updateAllocateState(root, pickers);
        return;
    }

    if (isDuplicate(pickers, row.serial_number)) {
        setLineError(picker, SERIAL_ALLOCATE_MESSAGES.duplicate);
        setFormError(root, SERIAL_ALLOCATE_MESSAGES.duplicate);
        return;
    }

    if (picker.chosen.length >= picker.qty) {
        setLineError(picker, SERIAL_ALLOCATE_MESSAGES.lineFull);
        return;
    }

    const prospective = pickers.flatMap((entry) => (
        entry === picker ? entry.chosen.concat([row]) : entry.chosen
    ));
    const nextBranches = {};
    prospective.forEach((item) => {
        if (item.branch_code) {
            nextBranches[item.branch_code] = true;
        }
    });
    if (Object.keys(nextBranches).length > 1) {
        setLineError(picker, SERIAL_ALLOCATE_MESSAGES.mixedBranch);
        setFormError(root, SERIAL_ALLOCATE_MESSAGES.mixedBranch);
        return;
    }

    picker.chosen.push(row);
    renderSelected(picker);
    picker.lastResults?.length && renderResults(picker, picker.lastResults);
    updateAllocateState(root, pickers);
};

const updateAllocateState = (root, pickers) => {
    const form = root.matches?.('form') ? root : root.querySelector('#hardware-action-serial-form');
    const submit = form?.querySelector('[data-hardware-action-submit]');
    const branches = uniqueBranches(pickers);
    const mixed = branches.length > 1;
    const complete = selectionComplete(pickers) && !mixed;
    const selectedCount = allChosen(pickers).length;
    const required = pickers.reduce((sum, picker) => sum + picker.qty, 0);

    if (mixed) {
        setFormError(root, SERIAL_ALLOCATE_MESSAGES.mixedBranch);
    } else {
        const current = root.querySelector('[data-hardware-serial-client-error]');
        if (current && (current.textContent === SERIAL_ALLOCATE_MESSAGES.mixedBranch
            || current.textContent === SERIAL_ALLOCATE_MESSAGES.duplicate
            || current.textContent === SERIAL_ALLOCATE_MESSAGES.incomplete)) {
            setFormError(root, '');
        }
    }

    const totals = root.querySelector('[data-hardware-serial-totals]');
    if (totals) {
        totals.textContent = `Total required: ${required} · Total selected: ${selectedCount}`;
    }

    const branchEl = root.querySelector('[data-hardware-serial-branch]');
    if (branchEl) {
        branchEl.textContent = branches.length === 1
            ? `Branch: ${displayBranch(branches[0])}`
            : 'Branch: Derived from selected serials';
    }

    if (!submit || form?.dataset.hardwareSubmitting === '1') {
        return;
    }

    const idle = submit.getAttribute('data-hardware-serial-submit-idle') || submit.textContent;
    submit.textContent = idle;
    if (complete) {
        submit.removeAttribute('disabled');
    } else {
        submit.setAttribute('disabled', 'disabled');
    }
};

const searchPicker = async (picker) => {
    const q = picker.query.value.trim();
    if (q.length < 2) {
        picker.results.textContent = '';
        return;
    }

    const url = new URL(picker.searchUrl, window.location.origin);
    url.searchParams.set('commerce_order_item_id', picker.itemId);
    url.searchParams.set('q', q);

    try {
        const response = await workspaceFetch(url.toString(), {
            headers: workspaceFetchHeaders('application/json'),
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            const errors = payload.errors || {};
            picker.results.textContent = (errors.serials || errors.branch || [payload.message || SERIAL_ALLOCATE_MESSAGES.wrongProduct])[0];
            return;
        }

        const serials = payload.serials ?? [];
        renderResults(picker, serials);
    } catch {
        picker.results.textContent = 'Search failed.';
    }
};

let activeSerialPickers = [];

export const bindHardwareActionForms = (root) => {
    bindSerialPickers(root);
    root.querySelectorAll('[data-hardware-action-form]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            void submitForm(form);
        });
    });
};

export const bindSerialPickers = (root) => {
    const pickers = collectPickers(root);
    if (pickers.length === 0) {
        activeSerialPickers = [];
        return pickers;
    }

    pickers.forEach((picker) => {
        picker.root = root;
        picker.pickers = pickers;
        if (!picker.query || !picker.results || !picker.selected || !picker.itemId || !picker.searchUrl) {
            return;
        }

        picker.query.addEventListener('input', () => {
            window.clearTimeout(picker.timer);
            picker.timer = window.setTimeout(() => {
                void searchPicker(picker);
            }, 200);
        });
        renderSelected(picker);
    });

    activeSerialPickers = pickers;
    updateAllocateState(root, pickers);

    return pickers;
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
    const isSerialForm = form.id === 'hardware-action-serial-form';

    if (isSerialForm) {
        if (form.dataset.hardwareSubmitting === '1') {
            return;
        }

        const host = form.querySelector('[data-hardware-serial-allocate]') ?? form;
        const pickers = activeSerialPickers;
        if (Array.isArray(pickers) && pickers.length) {
            const mixed = uniqueBranches(pickers).length > 1;
            if (!selectionComplete(pickers) || mixed) {
                setFormError(host, mixed ? SERIAL_ALLOCATE_MESSAGES.mixedBranch : SERIAL_ALLOCATE_MESSAGES.incomplete);
                updateAllocateState(host, pickers);
                return;
            }
        }

        form.dataset.hardwareSubmitting = '1';
        submit?.setAttribute('disabled', 'disabled');
        if (submit) {
            submit.textContent = 'Allocating…';
        }
    } else {
        submit?.setAttribute('disabled', 'disabled');
    }

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
            if (isSerialForm) {
                setFormError(form, message);
            }
            return;
        }

        handleSuccess(payload);
    } catch (error) {
        showError(root, error?.message ?? 'This hardware action could not be completed.');
    } finally {
        if (isSerialForm) {
            form.dataset.hardwareSubmitting = '';
            const pickers = activeSerialPickers;
            if (Array.isArray(pickers)) {
                updateAllocateState(form, pickers);
            } else {
                submit?.setAttribute('disabled', 'disabled');
                const idle = submit?.getAttribute('data-hardware-serial-submit-idle');
                if (submit && idle) {
                    submit.textContent = idle;
                }
            }
        } else {
            submit?.removeAttribute('disabled');
        }
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
    bindHardwareActionForms(content);
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
