import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { isTypingTarget, isMentionDropdownOpen, isSubmitModifier, isHelpShortcut, isQuickFilterShortcut, shouldBlockShortcutForTyping } from '../../resources/js/keyboard/guards';
import { initKeyboardShortcuts, resetKeyboardShortcuts } from '../../resources/js/keyboard';
import { getWorkspaceSession, resetWorkspaceSession } from '../../resources/js/workspace/session';

const HELP_MODAL_HTML = '<div id="keyboardShortcutsModal"></div>';

const buildDashboardWithFilter = () => {
    document.body.innerHTML = `
        ${HELP_MODAL_HTML}
        <div id="dashboard-page">
            <div class="dashboard-service-cases-card">
                <div class="dashboard-quick-filter dashboard-quick-filter--always-open"
                     data-dashboard-quick-filter
                     data-dashboard-quick-filter-always-open="true">
                    <div class="dashboard-quick-filter__control" data-dashboard-quick-filter-control>
                        <input type="search" id="dashboard-quick-filter-input" data-dashboard-quick-filter-input>
                    </div>
                </div>
            </div>
        </div>
    `;
};

const buildInlineEditor = ({ open = true } = {}) => {
    document.body.innerHTML = `
        ${HELP_MODAL_HTML}
        <div class="dashboard-service-cases-card">
            <div data-inline-transaction="true" data-incident-id="5">
                <button type="button" class="transaction-cell-trigger ${open ? 'd-none' : ''}">Add</button>
                <div class="transaction-inline-editor ${open ? '' : 'd-none'}">
                    <input type="text" class="transaction-inline-input" value="TXN-1">
                </div>
            </div>
        </div>
    `;
};

const mockBootstrapModal = () => {
    const helpModalShow = vi.fn();
    window.bootstrap = {
        Modal: {
            getOrCreateInstance: () => ({
                show: helpModalShow,
            }),
        },
    };

    return { helpModalShow };
};

describe('keyboard guards', () => {
    it('detects typing targets', () => {
        expect(isTypingTarget(document.createElement('input'))).toBe(true);
        expect(isTypingTarget(document.createElement('textarea'))).toBe(true);
        expect(isTypingTarget(document.createElement('select'))).toBe(true);

        const editable = document.createElement('div');
        editable.contentEditable = 'true';
        expect(isTypingTarget(editable)).toBe(true);

        expect(isTypingTarget(document.createElement('button'))).toBe(false);
        expect(isTypingTarget(null)).toBe(false);
    });

    it('detects submit modifier combinations', () => {
        expect(isSubmitModifier(new KeyboardEvent('keydown', { key: 'Enter', ctrlKey: true }))).toBe(true);
        expect(isSubmitModifier(new KeyboardEvent('keydown', { key: 'Enter', metaKey: true }))).toBe(true);
        expect(isSubmitModifier(new KeyboardEvent('keydown', { key: 'Enter' }))).toBe(false);
    });

    it('detects an open mention dropdown', () => {
        document.body.innerHTML = '<div class="mention-suggestions show" style="display: block;"></div>';
        expect(isMentionDropdownOpen()).toBe(true);

        document.body.innerHTML = '<div class="mention-suggestions show" style="display: none;"></div>';
        expect(isMentionDropdownOpen()).toBe(false);

        document.body.innerHTML = '<div class="mention-suggestions"></div>';
        expect(isMentionDropdownOpen()).toBe(false);
    });

    it('detects help and quick-filter shortcut keys', () => {
        expect(isHelpShortcut(new KeyboardEvent('keydown', { key: '?' }))).toBe(true);
        expect(isHelpShortcut(new KeyboardEvent('keydown', { key: '/', code: 'Slash', shiftKey: true }))).toBe(true);
        expect(isQuickFilterShortcut(new KeyboardEvent('keydown', { key: '/', code: 'Slash' }))).toBe(true);
        expect(isQuickFilterShortcut(new KeyboardEvent('keydown', { key: '/', code: 'Slash', shiftKey: true }))).toBe(false);
    });

    it('allows global search focus to pass the typing guard', () => {
        document.body.innerHTML = '<input id="global-search-input" type="search">';
        const searchInput = document.getElementById('global-search-input');

        expect(shouldBlockShortcutForTyping(searchInput)).toBe(false);
        expect(shouldBlockShortcutForTyping(document.createElement('textarea'))).toBe(true);
    });
});

describe('initKeyboardShortcuts', () => {
    /** @type {(() => void) | null} */
    let cleanupKeyboardShortcuts = null;

    afterEach(() => {
        cleanupKeyboardShortcuts?.();
        cleanupKeyboardShortcuts = null;
        resetKeyboardShortcuts();
        resetWorkspaceSession();
        delete window.bootstrap;
    });

    const bindKeyboardShortcuts = (options = {}) => {
        cleanupKeyboardShortcuts?.();
        cleanupKeyboardShortcuts = initKeyboardShortcuts(options);
    };

    it('opens the dashboard quick filter on / when not typing', () => {
        buildDashboardWithFilter();
        mockBootstrapModal();

        const openDashboardQuickFilter = vi.fn();
        bindKeyboardShortcuts({ openDashboardQuickFilter });

        document.dispatchEvent(new KeyboardEvent('keydown', { key: '/', bubbles: true }));

        expect(openDashboardQuickFilter).toHaveBeenCalled();
    });

    it('focuses the dashboard quick filter on / when no open handler is provided', () => {
        buildDashboardWithFilter();
        mockBootstrapModal();
        bindKeyboardShortcuts();

        const container = document.querySelector('[data-dashboard-quick-filter]');
        container.classList.add('dashboard-quick-filter--expanded');
        document.querySelector('[data-dashboard-quick-filter-control]')?.classList.remove('d-none');

        const input = document.querySelector('[data-dashboard-quick-filter-input]');
        const focusSpy = vi.spyOn(input, 'focus');
        const selectSpy = vi.spyOn(input, 'select');

        document.dispatchEvent(new KeyboardEvent('keydown', { key: '/', bubbles: true }));

        expect(focusSpy).toHaveBeenCalled();
        expect(selectSpy).toHaveBeenCalled();
    });

    it('does not focus the dashboard quick filter on / while typing in quick filter', () => {
        buildDashboardWithFilter();
        mockBootstrapModal();

        const openDashboardQuickFilter = vi.fn();
        bindKeyboardShortcuts({ openDashboardQuickFilter });

        const container = document.querySelector('[data-dashboard-quick-filter]');
        container.classList.add('dashboard-quick-filter--expanded');
        document.querySelector('[data-dashboard-quick-filter-control]')?.classList.remove('d-none');

        const input = document.querySelector('[data-dashboard-quick-filter-input]');

        input.dispatchEvent(new KeyboardEvent('keydown', { key: '/', code: 'Slash', bubbles: true }));

        expect(openDashboardQuickFilter).not.toHaveBeenCalled();
    });

    it('does not focus the dashboard quick filter on / outside the dashboard', () => {
        document.body.innerHTML = `
            ${HELP_MODAL_HTML}
            <input type="search" data-dashboard-quick-filter-input>
        `;
        mockBootstrapModal();
        bindKeyboardShortcuts();

        const input = document.querySelector('[data-dashboard-quick-filter-input]');
        const focusSpy = vi.spyOn(input, 'focus');

        document.dispatchEvent(new KeyboardEvent('keydown', { key: '/', bubbles: true }));

        expect(focusSpy).not.toHaveBeenCalled();
    });

    it('opens the keyboard shortcuts help modal on ?', () => {
        document.body.innerHTML = HELP_MODAL_HTML;
        const { helpModalShow } = mockBootstrapModal();
        bindKeyboardShortcuts();

        document.dispatchEvent(new KeyboardEvent('keydown', { key: '?', bubbles: true }));

        expect(helpModalShow).toHaveBeenCalled();
    });

    it('does not focus the dashboard quick filter on / while typing in a textarea', () => {
        buildDashboardWithFilter();
        mockBootstrapModal();
        bindKeyboardShortcuts();

        const textarea = document.createElement('textarea');
        document.body.appendChild(textarea);
        const filterInput = document.querySelector('[data-dashboard-quick-filter-input]');
        const focusSpy = vi.spyOn(filterInput, 'focus');

        textarea.dispatchEvent(new KeyboardEvent('keydown', { key: '/', code: 'Slash', bubbles: true }));

        expect(focusSpy).not.toHaveBeenCalled();
    });

    it('focuses the dashboard quick filter on / while global search is focused', () => {
        buildDashboardWithFilter();
        document.body.insertAdjacentHTML('afterbegin', '<input id="global-search-input" type="search">');
        mockBootstrapModal();

        const openDashboardQuickFilter = vi.fn();
        bindKeyboardShortcuts({ openDashboardQuickFilter });

        const searchInput = document.getElementById('global-search-input');

        searchInput.dispatchEvent(new KeyboardEvent('keydown', { key: '/', code: 'Slash', bubbles: true }));

        expect(openDashboardQuickFilter).toHaveBeenCalled();
    });

    it('opens the help modal on Shift+/ when key is slash', () => {
        document.body.innerHTML = HELP_MODAL_HTML;
        const { helpModalShow } = mockBootstrapModal();
        bindKeyboardShortcuts();

        document.dispatchEvent(new KeyboardEvent('keydown', {
            key: '/',
            code: 'Slash',
            shiftKey: true,
            bubbles: true,
        }));

        expect(helpModalShow).toHaveBeenCalled();
    });

    it('does not open the help modal on ? while typing in a textarea', () => {
        document.body.innerHTML = `
            ${HELP_MODAL_HTML}
            <textarea id="notes"></textarea>
        `;
        const { helpModalShow } = mockBootstrapModal();
        bindKeyboardShortcuts();

        document.getElementById('notes').dispatchEvent(new KeyboardEvent('keydown', { key: '?', bubbles: true }));

        expect(helpModalShow).not.toHaveBeenCalled();
    });

    it('opens the help modal when global search is focused', () => {
        document.body.innerHTML = `
            ${HELP_MODAL_HTML}
            <input id="global-search-input" type="search">
        `;
        const { helpModalShow } = mockBootstrapModal();
        bindKeyboardShortcuts();

        document.getElementById('global-search-input').dispatchEvent(new KeyboardEvent('keydown', {
            key: '?',
            bubbles: true,
        }));

        expect(helpModalShow).toHaveBeenCalled();
    });

    it('closes an open inline transaction editor on Esc', () => {
        buildInlineEditor({ open: true });
        mockBootstrapModal();

        const session = getWorkspaceSession();
        session.acquire('inline-transaction', { incidentId: 5 });

        let closed = false;
        bindKeyboardShortcuts({
            closeOpenInlineEditor: () => {
                closed = true;
                session.release('inline-transaction');
                return true;
            },
        });

        const input = document.querySelector('.transaction-inline-input');
        const event = new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true });
        input.dispatchEvent(event);

        expect(closed).toBe(true);
        expect(event.defaultPrevented).toBe(true);
        expect(session.isActive('inline-transaction')).toBe(false);
    });

    it('does not prevent Esc when no inline editor is open', () => {
        buildInlineEditor({ open: false });
        mockBootstrapModal();

        let closed = false;
        bindKeyboardShortcuts({
            closeOpenInlineEditor: () => false,
        });

        const event = new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true });
        document.dispatchEvent(event);

        expect(closed).toBe(false);
        expect(event.defaultPrevented).toBe(false);
    });

    it('submits the active modal form on Ctrl+Enter', () => {
        document.body.innerHTML = `
            ${HELP_MODAL_HTML}
            <div class="modal show" id="workspaceModal">
                <form id="active-modal-form">
                    <input type="text" name="body" value="test">
                    <button type="submit">Save</button>
                </form>
            </div>
        `;
        mockBootstrapModal();
        bindKeyboardShortcuts();

        const form = document.getElementById('active-modal-form');
        const submitHandler = vi.fn((event) => event.preventDefault());
        form.addEventListener('submit', submitHandler);

        document.dispatchEvent(new KeyboardEvent('keydown', {
            key: 'Enter',
            ctrlKey: true,
            bubbles: true,
        }));

        expect(submitHandler).toHaveBeenCalled();
    });

    it('submits the active modal form on Meta+Enter', () => {
        document.body.innerHTML = `
            ${HELP_MODAL_HTML}
            <div class="modal show" id="quickCreateModal">
                <form id="quick-form">
                    <button type="submit">Create</button>
                </form>
            </div>
        `;
        mockBootstrapModal();
        bindKeyboardShortcuts();

        const form = document.getElementById('quick-form');
        const submitHandler = vi.fn((event) => event.preventDefault());
        form.addEventListener('submit', submitHandler);

        document.dispatchEvent(new KeyboardEvent('keydown', {
            key: 'Enter',
            metaKey: true,
            bubbles: true,
        }));

        expect(submitHandler).toHaveBeenCalled();
    });

    it('does not submit when workspace submit is busy', () => {
        document.body.innerHTML = `
            ${HELP_MODAL_HTML}
            <div class="modal show">
                <form id="busy-form">
                    <button type="submit">Save</button>
                </form>
            </div>
        `;
        mockBootstrapModal();

        const isWorkspaceSubmitBusy = vi.fn(() => true);
        const requestSubmit = vi.spyOn(HTMLFormElement.prototype, 'requestSubmit').mockImplementation(() => {});

        bindKeyboardShortcuts({
            isWorkspaceSubmitBusy,
        });

        document.dispatchEvent(new KeyboardEvent('keydown', {
            key: 'Enter',
            ctrlKey: true,
            bubbles: true,
        }));

        expect(isWorkspaceSubmitBusy).toHaveBeenCalled();
        expect(requestSubmit).not.toHaveBeenCalled();

        requestSubmit.mockRestore();
    });

    it('does not submit on Ctrl+Enter when mention dropdown is open', () => {
        document.body.innerHTML = `
            ${HELP_MODAL_HTML}
            <div class="mention-suggestions show" style="display: block;"></div>
            <div class="modal show">
                <form id="mention-form">
                    <button type="submit">Save</button>
                </form>
            </div>
        `;
        mockBootstrapModal();
        bindKeyboardShortcuts();

        const form = document.getElementById('mention-form');
        const submitHandler = vi.fn((event) => event.preventDefault());
        form.addEventListener('submit', submitHandler);

        document.dispatchEvent(new KeyboardEvent('keydown', {
            key: 'Enter',
            ctrlKey: true,
            bubbles: true,
        }));

        expect(submitHandler).not.toHaveBeenCalled();
    });

    it('submits from a focused modal field on Ctrl+Enter', () => {
        document.body.innerHTML = `
            ${HELP_MODAL_HTML}
            <div class="modal show">
                <form id="focused-form">
                    <textarea name="body"></textarea>
                    <button type="submit">Save</button>
                </form>
            </div>
        `;
        mockBootstrapModal();
        bindKeyboardShortcuts();

        const form = document.getElementById('focused-form');
        const submitHandler = vi.fn((event) => event.preventDefault());
        form.addEventListener('submit', submitHandler);

        document.dispatchEvent(new KeyboardEvent('keydown', {
            key: 'Enter',
            ctrlKey: true,
            bubbles: true,
        }));

        expect(submitHandler).toHaveBeenCalled();
    });
});

describe('closeOpenInlineEditor integration', () => {
    afterEach(() => {
        resetWorkspaceSession();
    });

    it('releases inline-transaction session when Esc closes the editor', () => {
        buildInlineEditor({ open: true });

        const card = document.querySelector('.dashboard-service-cases-card');
        const session = getWorkspaceSession();

        const closeInlineEditor = (cell) => {
            cell.querySelector('.transaction-inline-editor')?.classList.add('d-none');
            cell.querySelector('.transaction-cell-trigger')?.classList.remove('d-none');
            session.release('inline-transaction');
        };

        const closeOpenInlineEditor = () => {
            const openEditor = card.querySelector('.transaction-inline-editor:not(.d-none)');

            if (!openEditor) {
                return false;
            }

            const cell = openEditor.closest('[data-inline-transaction="true"]');
            closeInlineEditor(cell);

            return true;
        };

        session.acquire('inline-transaction', { incidentId: 5 });
        expect(session.isActive('inline-transaction')).toBe(true);

        closeOpenInlineEditor();

        expect(session.isActive('inline-transaction')).toBe(false);
        expect(card.querySelector('.transaction-inline-editor')?.classList.contains('d-none')).toBe(true);
    });
});
