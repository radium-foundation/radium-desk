import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createTodoModal, isCustomer360Open, setTodoModalStacked } from '../../resources/js/todo-modal';

vi.mock('../../resources/js/core/toast', () => ({
    showAppToast: vi.fn(),
}));

import { showAppToast } from '../../resources/js/core/toast';

const modalShow = vi.fn();
const modalHide = vi.fn();
const modalInstances = new Map();

const createModalInstance = (element) => {
    if (modalInstances.has(element)) {
        return modalInstances.get(element);
    }

    const instance = {
        _isTransitioning: false,
        _config: { keyboard: true },
        show: () => {
            modalShow();
            element.classList.add('show');
            instance._isTransitioning = false;
        },
        hide: () => {
            modalHide();
            element.classList.remove('show');
        },
    };

    modalInstances.set(element, instance);

    return instance;
};

vi.mock('bootstrap', () => ({
    Modal: {
        getOrCreateInstance: vi.fn((element) => createModalInstance(element)),
        getInstance: vi.fn((element) => modalInstances.get(element) ?? createModalInstance(element)),
    },
}));

const pressEscape = (target) => {
    document.dispatchEvent(new KeyboardEvent('keydown', {
        key: 'Escape',
        bubbles: true,
        cancelable: true,
    }));

    if (target instanceof HTMLElement) {
        target.dispatchEvent(new KeyboardEvent('keydown', {
            key: 'Escape',
            bubbles: true,
            cancelable: true,
        }));
    }
};

describe('todo modal', () => {
    beforeEach(() => {
        document.body.innerHTML = `
            <button type="button" data-todo-modal-open data-todo-url="/todos">Open</button>
            <div class="modal" data-todo-modal data-todo-endpoint="/todos" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div data-todo-modal-loading hidden></div>
                        <div data-todo-modal-error class="d-none"></div>
                        <div data-todo-modal-content-host></div>
                    </div>
                </div>
            </div>
        `;
        modalShow.mockClear();
        modalHide.mockClear();
        modalInstances.clear();
        showAppToast.mockClear();
    });

    afterEach(() => {
        document.body.innerHTML = '';
        document.body.className = '';
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('opens modal and loads panel html without navigating away', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            text: async () => '<div data-todo-panel="list">Panel</div>',
        }));

        const api = createTodoModal(document);
        expect(api).not.toBeNull();

        await api.open('/todos');

        expect(modalShow).toHaveBeenCalled();
        expect(document.querySelector('[data-todo-modal-content-host]').innerHTML)
            .toContain('data-todo-panel="list"');
        expect(fetch).toHaveBeenCalledWith('/todos', expect.objectContaining({
            headers: expect.objectContaining({
                'X-Requested-With': 'XMLHttpRequest',
            }),
        }));
    });

    it('navigates in-panel for todo links', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                text: async () => '<a href="/todos/12">Item</a>',
            })
            .mockResolvedValueOnce({
                ok: true,
                text: async () => '<div data-todo-panel="detail">Detail</div>',
            });
        vi.stubGlobal('fetch', fetchMock);

        const api = createTodoModal(document);
        await api.open('/todos');

        expect(document.querySelector('a[href="/todos/12"]')).not.toBeNull();

        document.querySelector('a[href="/todos/12"]').click();

        await vi.waitFor(() => {
            expect(document.querySelector('[data-todo-modal-content-host]').innerHTML)
                .toContain('data-todo-panel="detail"');
        });

        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it.each([
        ['list link', '<a href="/todos/1" class="todo-panel__row">Row</a>', 'a.todo-panel__row'],
        ['list select', '<select name="status"><option>Open</option></select>', 'select[name="status"]'],
        ['detail button', '<button type="button" class="btn btn-success btn-sm">Complete</button>', 'button'],
        ['create input', '<input id="title" type="text" />', '#title'],
        ['create textarea', '<textarea id="description"></textarea>', '#description'],
        ['create select', '<select id="priority"><option>Normal</option></select>', '#priority'],
        ['edit input', '<input id="title" type="text" value="Edit me" />', '#title'],
    ])('closes on Escape when focus is inside %s', async (_label, html, selector) => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            text: async () => html,
        }));

        const api = createTodoModal(document);
        await api.open('/todos');

        const control = document.querySelector(selector);
        expect(control).not.toBeNull();
        control.focus();

        modalHide.mockClear();
        pressEscape(control);

        expect(modalHide).toHaveBeenCalledTimes(1);
    });

    it('closes on Escape when focus is outside modal content but modal is open', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            text: async () => '<div data-todo-panel="detail">Detail</div>',
        }));

        const api = createTodoModal(document);
        await api.open('/todos');

        document.body.focus();
        expect(document.querySelector('[data-todo-modal]').contains(document.activeElement)).toBe(false);

        modalHide.mockClear();
        pressEscape();

        expect(modalHide).toHaveBeenCalledTimes(1);
    });

    it('defers close until shown when Escape is pressed during the show transition', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            text: async () => '<div data-todo-panel="list">Panel</div>',
        }));

        const modalEl = document.querySelector('[data-todo-modal]');
        const api = createTodoModal(document);

        modalEl.classList.add('show');
        const instance = (await import('bootstrap')).Modal.getOrCreateInstance(modalEl);
        instance._isTransitioning = true;

        modalHide.mockClear();
        pressEscape();

        expect(modalHide).not.toHaveBeenCalled();

        modalEl.dispatchEvent(new Event('shown.bs.modal'));

        expect(modalHide).toHaveBeenCalledTimes(1);
        expect(api.modal.classList.contains('show')).toBe(false);
    });

    it('submits create form via xhr and keeps modal content updated', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                text: async () => '<form method="POST" action="/todos"><input name="title" /></form>',
            })
            .mockResolvedValueOnce({
                ok: true,
                status: 200,
                url: 'http://localhost/todos/9',
                headers: {
                    get: (name) => (name === 'X-Todo-Status' ? 'todo-created' : null),
                },
                text: async () => '<div data-todo-panel="detail">Created</div>',
            });
        vi.stubGlobal('fetch', fetchMock);

        const api = createTodoModal(document);
        await api.open('/todos/create');

        const form = document.querySelector('form[action="/todos"]');
        form.querySelector('input[name="title"]').value = 'New item';
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

        await vi.waitFor(() => {
            expect(document.querySelector('[data-todo-modal-content-host]').innerHTML)
                .toContain('data-todo-panel="detail"');
        });

        expect(fetchMock).toHaveBeenLastCalledWith('/todos', expect.objectContaining({
            method: 'POST',
            headers: expect.objectContaining({
                'X-Requested-With': 'XMLHttpRequest',
            }),
        }));
        expect(showAppToast).toHaveBeenCalledWith('To-do created successfully.', 'success');
        expect(window.location.pathname).not.toMatch(/^\/todos\/\d+$/);
    });

    it('keeps validation errors inside modal on failed create', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                text: async () => '<form method="POST" action="/todos"><input name="title" id="title" /></form>',
            })
            .mockResolvedValueOnce({
                ok: false,
                status: 422,
                headers: { get: () => null },
                text: async () => '<div data-todo-panel="form"><div class="invalid-feedback">The title field is required.</div></div>',
            });
        vi.stubGlobal('fetch', fetchMock);

        const api = createTodoModal(document);
        await api.open('/todos/create');

        document.querySelector('form[action="/todos"]').dispatchEvent(new Event('submit', {
            bubbles: true,
            cancelable: true,
        }));

        await vi.waitFor(() => {
            expect(document.querySelector('[data-todo-modal-content-host]').innerHTML)
                .toContain('The title field is required.');
        });

        expect(showAppToast).not.toHaveBeenCalled();
    });

    it('submits complete action via xhr without navigating away', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce({
                ok: true,
                text: async () => '<form method="POST" action="/todos/5/complete"><button type="submit">Complete</button></form>',
            })
            .mockResolvedValueOnce({
                ok: true,
                status: 200,
                url: 'http://localhost/todos/5',
                headers: {
                    get: (name) => (name === 'X-Todo-Status' ? 'todo-completed' : null),
                },
                text: async () => '<div data-todo-panel="detail">Completed</div>',
            });
        vi.stubGlobal('fetch', fetchMock);

        const api = createTodoModal(document);
        await api.open('/todos/5');

        document.querySelector('form[action="/todos/5/complete"]').dispatchEvent(new Event('submit', {
            bubbles: true,
            cancelable: true,
        }));

        await vi.waitFor(() => {
            expect(document.querySelector('[data-todo-modal-content-host]').innerHTML)
                .toContain('Completed');
        });

        expect(fetchMock).toHaveBeenLastCalledWith('/todos/5/complete', expect.objectContaining({
            method: 'POST',
        }));
        expect(showAppToast).toHaveBeenCalledWith('To-do marked complete.', 'success');
    });

    it('stacks above Customer 360 when drawer is open', async () => {
        document.body.innerHTML += `
            <div class="customer-360-drawer is-open" data-customer-360-drawer></div>
            <div class="modal-backdrop show"></div>
        `;

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            text: async () => '<div data-todo-panel="list">Panel</div>',
        }));

        const api = createTodoModal(document);
        await api.open('/todos');

        expect(isCustomer360Open()).toBe(true);
        expect(api.modal.classList.contains('todo-modal--stacked')).toBe(true);
        expect(document.body.classList.contains('todo-modal-stacked-open')).toBe(true);
        expect(document.querySelector('.modal-backdrop')?.classList.contains('todo-modal-backdrop')).toBe(true);
    });

    it('clears stacked state when todo modal closes', () => {
        document.body.innerHTML += '<div class="customer-360-drawer is-open" data-customer-360-drawer></div>';
        const modalEl = document.querySelector('[data-todo-modal]');
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop show todo-modal-backdrop';
        document.body.appendChild(backdrop);

        setTodoModalStacked(modalEl, true);
        setTodoModalStacked(modalEl, false);

        expect(modalEl.classList.contains('todo-modal--stacked')).toBe(false);
        expect(document.body.classList.contains('todo-modal-stacked-open')).toBe(false);
        expect(backdrop.classList.contains('todo-modal-backdrop')).toBe(false);
    });

    it('escape stops propagation when stacked over Customer 360', async () => {
        document.body.innerHTML += '<div class="customer-360-drawer is-open" data-customer-360-drawer></div>';

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            text: async () => '<div data-todo-panel="list">Panel</div>',
        }));

        const api = createTodoModal(document);
        await api.open('/todos');
        api.modal.classList.add('todo-modal--stacked');

        let customer360EscapeFired = false;
        document.addEventListener('keydown', () => {
            customer360EscapeFired = true;
        });

        pressEscape();
        expect(modalHide).toHaveBeenCalled();
        expect(customer360EscapeFired).toBe(false);
    });

    it('does not close todo on escape when Customer 360 is on top', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            text: async () => '<div data-todo-panel="list">Panel</div>',
        }));

        const api = createTodoModal(document);
        await api.open('/todos');

        document.body.insertAdjacentHTML('beforeend', '<div class="customer-360-drawer is-open" data-customer-360-drawer></div>');
        api.modal.classList.remove('todo-modal--stacked');
        document.body.classList.remove('todo-modal-stacked-open');

        pressEscape();
        expect(modalHide).not.toHaveBeenCalled();
    });
});
