/**
 * Contextual To-Do modal — keeps the user on the current page.
 * Uses Bootstrap modal (same pattern as workspace-modal-host).
 */

import * as bootstrap from 'bootstrap';
import { showAppToast } from './core/toast';

const TODO_FLASH_MESSAGES = {
    'todo-created': 'To-do created successfully.',
    'todo-updated': 'To-do updated successfully.',
    'todo-assigned': 'To-do assignee updated successfully.',
    'todo-completed': 'To-do marked complete.',
    'todo-reopened': 'To-do reopened.',
    'todo-cancelled': 'To-do cancelled.',
    'todo-deleted': 'To-do deleted.',
};

let documentTriggersBound = false;
let documentEscapeBound = false;
let activeModalApi = null;

const isTodoUrl = (url) => {
    try {
        const parsed = new URL(url, window.location.origin);

        return parsed.origin === window.location.origin
            && /^\/todos(?:\/|$)/.test(parsed.pathname);
    } catch (error) {
        return false;
    }
};

const STACKED_BODY_CLASS = 'todo-modal-stacked-open';
const STACKED_MODAL_CLASS = 'todo-modal--stacked';
const BACKDROP_CLASS = 'todo-modal-backdrop';

const isCustomer360Open = () => Boolean(
    document.querySelector('.customer-360-drawer.is-open'),
);

const elevateTodoModalBackdrop = () => {
    document.querySelectorAll('.modal-backdrop.show').forEach((backdrop) => {
        backdrop.classList.add(BACKDROP_CLASS);
    });
};

const resetTodoModalBackdrop = () => {
    document.querySelectorAll(`.modal-backdrop.${BACKDROP_CLASS}`).forEach((backdrop) => {
        backdrop.classList.remove(BACKDROP_CLASS);
    });
};

const setTodoModalStacked = (modalEl, stacked) => {
    if (stacked) {
        modalEl.classList.add(STACKED_MODAL_CLASS);
        document.body.classList.add(STACKED_BODY_CLASS);
        elevateTodoModalBackdrop();

        return;
    }

    modalEl.classList.remove(STACKED_MODAL_CLASS);
    document.body.classList.remove(STACKED_BODY_CLASS);
    resetTodoModalBackdrop();
};

const isTopOpenModal = (element) => {
    const openModals = Array.from(document.querySelectorAll('.modal.show'));

    return openModals.length > 0 && openModals[openModals.length - 1] === element;
};

const bindDocumentEscape = () => {
    if (documentEscapeBound) {
        return;
    }

    documentEscapeBound = true;

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || !activeModalApi) {
            return;
        }

        const modal = activeModalApi.modal;

        if (!modal?.classList.contains('show')) {
            return;
        }

        if (isCustomer360Open() && !modal.classList.contains(STACKED_MODAL_CLASS)) {
            return;
        }

        if (modal.classList.contains(STACKED_MODAL_CLASS)) {
            event.preventDefault();
            event.stopImmediatePropagation();
            activeModalApi.close();

            return;
        }

        if (!isTopOpenModal(modal)) {
            return;
        }

        event.preventDefault();
        activeModalApi.close();
    }, true);
};

const createTodoModal = (root = document) => {
    const modalEl = root.querySelector('[data-todo-modal]') || document.querySelector('[data-todo-modal]');

    if (!modalEl) {
        activeModalApi = null;

        return null;
    }

    if (modalEl.dataset.todoModalReady === '1' && activeModalApi) {
        return activeModalApi;
    }

    const contentHost = modalEl.querySelector('[data-todo-modal-content-host]');
    const loading = modalEl.querySelector('[data-todo-modal-loading]');
    const error = modalEl.querySelector('[data-todo-modal-error]');
    const defaultEndpoint = modalEl.getAttribute('data-todo-endpoint') || '/todos';
    const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl, {
        backdrop: true,
        keyboard: true,
        focus: true,
    });

    let abortController = null;
    let lastFocused = null;
    let currentUrl = defaultEndpoint;

    const setLoading = (isLoading) => {
        if (loading) {
            loading.hidden = !isLoading;
        }
    };

    const setError = (message) => {
        if (!error) {
            return;
        }

        if (!message) {
            error.classList.add('d-none');
            error.textContent = '';
            return;
        }

        error.textContent = message;
        error.classList.remove('d-none');
    };

    const showFlash = (statusKey) => {
        const message = TODO_FLASH_MESSAGES[statusKey];

        if (message) {
            showAppToast(message, 'success');
        }
    };

    const applyPanelHtml = (html, responseUrl = null) => {
        if (contentHost) {
            contentHost.innerHTML = html;
        }

        if (responseUrl) {
            currentUrl = responseUrl;
        }

        setLoading(false);
    };

    const load = async (url, { replace = true } = {}) => {
        const target = url || defaultEndpoint;
        currentUrl = target;
        setError('');
        setLoading(true);

        if (replace && contentHost) {
            contentHost.innerHTML = '';
        }

        if (abortController) {
            abortController.abort();
        }

        abortController = new AbortController();

        try {
            const response = await fetch(target, {
                headers: {
                    Accept: 'text/html',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                signal: abortController.signal,
            });

            if (!response.ok) {
                throw new Error(`Unable to load to-dos (${response.status})`);
            }

            const html = await response.text();
            applyPanelHtml(html, target);
        } catch (err) {
            if (err?.name === 'AbortError') {
                return;
            }

            setLoading(false);
            setError(err?.message || 'Unable to load to-dos.');
        }
    };

    const submitMutation = async (form) => {
        const action = form.getAttribute('action') || currentUrl;

        if (!isTodoUrl(action)) {
            return;
        }

        setError('');
        setLoading(true);

        if (abortController) {
            abortController.abort();
        }

        abortController = new AbortController();

        const formData = new FormData(form);

        try {
            const response = await fetch(action, {
                method: 'POST',
                body: formData,
                headers: {
                    Accept: 'text/html',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                signal: abortController.signal,
            });

            const html = await response.text();
            const flashStatus = response.headers.get('X-Todo-Status');

            if (response.status === 422) {
                applyPanelHtml(html);
                return;
            }

            if (!response.ok) {
                throw new Error(`Unable to save to-do (${response.status})`);
            }

            const responseUrl = response.url || action;
            applyPanelHtml(html, responseUrl);
            showFlash(flashStatus);
        } catch (err) {
            if (err?.name === 'AbortError') {
                return;
            }

            setLoading(false);
            setError(err?.message || 'Unable to save to-do.');
        }
    };

    const syncModalKeyboard = () => {
        if (!modalEl.classList.contains('show')) {
            return;
        }

        bsModal._config = bsModal._config ?? { keyboard: true };
        bsModal._config.keyboard = !isCustomer360Open()
            || modalEl.classList.contains(STACKED_MODAL_CLASS);
    };

    const watchCustomer360Stacking = () => {
        if (modalEl.dataset.todoModalC360Watch === '1') {
            return;
        }

        modalEl.dataset.todoModalC360Watch = '1';

        document.addEventListener('customer360:open', () => {
            if (!modalEl.classList.contains('show') || modalEl.classList.contains(STACKED_MODAL_CLASS)) {
                return;
            }

            syncModalKeyboard();
        });

        const bodyClassObserver = new MutationObserver(() => {
            if (!modalEl.classList.contains('show')) {
                return;
            }

            syncModalKeyboard();
        });

        bodyClassObserver.observe(document.body, {
            attributes: true,
            attributeFilter: ['class'],
        });
    };

    let closeAfterShow = false;

    const close = () => {
        if (!modalEl.classList.contains('show')) {
            return;
        }

        const instance = bootstrap.Modal.getInstance(modalEl);

        if (instance?._isTransitioning) {
            closeAfterShow = true;

            return;
        }

        bsModal.hide();
    };

    const open = async (url = defaultEndpoint) => {
        lastFocused = document.activeElement;
        bsModal.show();
        setTodoModalStacked(modalEl, isCustomer360Open());
        await load(url);
    };

    const onContentClick = (event) => {
        const link = event.target.closest('a[href]');

        if (!link || !contentHost?.contains(link)) {
            return;
        }

        const href = link.getAttribute('href');

        if (!href || href.startsWith('#') || !isTodoUrl(href)) {
            return;
        }

        event.preventDefault();
        void load(href);
    };

    const onContentSubmit = (event) => {
        const form = event.target;

        if (!(form instanceof HTMLFormElement) || !contentHost?.contains(form)) {
            return;
        }

        const method = (form.getAttribute('method') || 'get').toLowerCase();
        const action = form.getAttribute('action') || currentUrl;

        if (!isTodoUrl(action)) {
            return;
        }

        event.preventDefault();

        if (method === 'get') {
            const params = new URLSearchParams(new FormData(form));
            const url = new URL(action, window.location.origin);
            url.search = params.toString();
            void load(`${url.pathname}${url.search}`);

            return;
        }

        void submitMutation(form);
    };

    contentHost?.addEventListener('click', onContentClick);
    contentHost?.addEventListener('submit', onContentSubmit);

    modalEl.addEventListener('show.bs.modal', () => {
        setTodoModalStacked(modalEl, isCustomer360Open());
        syncModalKeyboard();
    });

    modalEl.addEventListener('shown.bs.modal', () => {
        if (isCustomer360Open()) {
            setTodoModalStacked(modalEl, true);
        }

        syncModalKeyboard();

        if (!closeAfterShow) {
            return;
        }

        closeAfterShow = false;
        bsModal.hide();
    });

    modalEl.addEventListener('hidden.bs.modal', () => {
        closeAfterShow = false;
        setTodoModalStacked(modalEl, false);
        if (bsModal._config) {
            bsModal._config.keyboard = true;
        }

        if (abortController) {
            abortController.abort();
            abortController = null;
        }

        if (contentHost) {
            contentHost.innerHTML = '';
        }

        setError('');
        setLoading(false);

        if (lastFocused && typeof lastFocused.focus === 'function') {
            lastFocused.focus();
        }
    });

    bindDocumentEscape();
    watchCustomer360Stacking();

    modalEl.dataset.todoModalReady = '1';

    const api = { open, close, load, modal: modalEl };
    activeModalApi = api;

    if (!documentTriggersBound) {
        documentTriggersBound = true;
        document.addEventListener('click', (event) => {
            const trigger = event.target.closest('[data-todo-modal-open]');

            if (!trigger || !activeModalApi) {
                return;
            }

            event.preventDefault();
            const url = trigger.getAttribute('data-todo-url')
                || trigger.getAttribute('href')
                || activeModalApi.modal.getAttribute('data-todo-endpoint')
                || '/todos';
            void activeModalApi.open(isTodoUrl(url) ? url : '/todos');
        });
    }

    return api;
};

const initTodoModal = () => createTodoModal(document);

export {
    createTodoModal,
    initTodoModal,
    isCustomer360Open,
    isTodoUrl,
    setTodoModalStacked,
};
