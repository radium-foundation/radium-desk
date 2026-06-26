import { describe, expect, it, beforeEach } from 'vitest';
import { createBusyStateManager } from '../../../resources/js/workspace/busy-state';

describe('createBusyStateManager', () => {
    let host;
    let modalContent;
    let busyState;

    beforeEach(() => {
        host = document.createElement('div');
        modalContent = document.createElement('div');
        modalContent.setAttribute('data-workspace-modal-content', '');
        host.appendChild(modalContent);
        document.body.appendChild(host);
        busyState = createBusyStateManager(host);
    });

    it('tracks busy state with setBusy, clearBusy, and isBusy', () => {
        expect(busyState.isBusy()).toBe(false);

        busyState.setBusy('loading');
        expect(busyState.isBusy()).toBe(true);
        expect(busyState.isBusy('loading')).toBe(true);
        expect(busyState.isBusy('submit')).toBe(false);

        busyState.clearBusy('loading');
        expect(busyState.isBusy()).toBe(false);
    });

    it('disables modal content while loading', () => {
        busyState.setBusy('loading');
        expect(modalContent.classList.contains('pe-none')).toBe(true);

        busyState.clearBusy('loading');
        expect(modalContent.classList.contains('pe-none')).toBe(false);
    });

    it('shows a spinner and disables submit buttons during submit', () => {
        const form = document.createElement('form');
        const submitButton = document.createElement('button');
        submitButton.type = 'submit';
        submitButton.innerHTML = '<i class="bi bi-person-check"></i> Assign';
        form.appendChild(submitButton);
        host.appendChild(form);

        busyState.setBusy('submit', form);

        expect(submitButton.disabled).toBe(true);
        expect(submitButton.innerHTML).toContain('spinner-border');
        expect(busyState.isBusy('submit')).toBe(true);

        busyState.clearBusy('submit', form);

        expect(submitButton.disabled).toBe(false);
        expect(submitButton.innerHTML).toContain('Assign');
        expect(busyState.isBusy('submit')).toBe(false);
    });
});
