import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    getFormSnapshot,
    initWorkspaceDialogShell,
} from '../../../resources/js/workspace/dialog-shell';

const buildActionDialogForm = (selectedAction = 'assign') => `
    <form data-workspace-action-dialog data-workspace-action-form="action">
        <input type="hidden" name="_token" value="csrf-token">
        <input type="hidden" name="workspace_context" value="customer">
        <input type="hidden" name="action_type" value="${selectedAction}" data-workspace-action-type-input>
        <div class="workspace-action-panel" data-workspace-action-panel="assign">
            <select name="assigned_to_user_id" ${selectedAction === 'assign' ? '' : 'disabled'}>
                <option value="1" selected>Engineer</option>
            </select>
        </div>
        <div class="workspace-action-panel d-none" data-workspace-action-panel="close">
            <select name="close_reason" ${selectedAction === 'close' ? '' : 'disabled'}>
                <option value="" selected></option>
                <option value="issue_resolved">Resolved</option>
            </select>
        </div>
        <textarea name="body"></textarea>
    </form>
`;

const buildSimpleForm = () => `
    <form data-workspace-action-form="link-order">
        <input type="hidden" name="_token" value="csrf-token">
        <input type="hidden" name="workspace_context" value="customer">
        <input type="text" name="order_id" value="RD100">
    </form>
`;

describe('getFormSnapshot', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('ignores csrf, workspace context, and action_type fields', () => {
        document.body.innerHTML = buildActionDialogForm('assign');
        const form = document.querySelector('form');
        const snapshot = JSON.parse(getFormSnapshot(form));

        expect(snapshot).not.toHaveProperty('_token');
        expect(snapshot).not.toHaveProperty('workspace_context');
        expect(snapshot).not.toHaveProperty('action_type');
        expect(snapshot).toEqual({
            assigned_to_user_id: '1',
            close_reason: '',
            body: '',
        });
    });

    it('does not mark manage case dirty when only the action tab changes', () => {
        document.body.innerHTML = buildActionDialogForm('assign');
        const form = document.querySelector('form');
        const initialSnapshot = getFormSnapshot(form);

        form.querySelector('[name="action_type"]').value = 'close';
        form.querySelector('[name="assigned_to_user_id"]').disabled = true;
        const closeReason = form.querySelector('[name="close_reason"]');
        closeReason.disabled = false;

        expect(getFormSnapshot(form)).toBe(initialSnapshot);
    });

    it('marks manage case dirty when a user-editable value changes', () => {
        document.body.innerHTML = buildActionDialogForm('assign');
        const form = document.querySelector('form');
        const initialSnapshot = getFormSnapshot(form);

        form.querySelector('[name="body"]').value = 'Updated remark';

        expect(getFormSnapshot(form)).not.toBe(initialSnapshot);
    });

    it('marks simple workspace forms dirty when editable values change', () => {
        document.body.innerHTML = buildSimpleForm();
        const form = document.querySelector('form');
        const initialSnapshot = getFormSnapshot(form);

        form.querySelector('[name="order_id"]').value = 'RD200';

        expect(getFormSnapshot(form)).not.toBe(initialSnapshot);
    });
});

describe('initWorkspaceDialogShell discard confirmation', () => {
    beforeEach(() => {
        document.body.innerHTML = `
            <div data-workspace-modal-host>
                <div class="workspace-discard-confirm" hidden>
                    <button type="button" data-workspace-discard-cancel>Cancel</button>
                    <button type="button" data-workspace-discard-apply>Discard</button>
                </div>
                <div data-workspace-modal-content></div>
            </div>
        `;
    });

    it('does not stack discard confirmations on repeated hide attempts', () => {
        const host = document.querySelector('[data-workspace-modal-host]');
        const modalContent = host.querySelector('[data-workspace-modal-content]');
        modalContent.innerHTML = `
            <form data-workspace-action-form="remark">
                <textarea name="body">changed</textarea>
            </form>
        `;

        initWorkspaceDialogShell(host, modalContent);

        const form = modalContent.querySelector('form');
        const initialSnapshot = getFormSnapshot(form);
        form.querySelector('[name="body"]').value = 'dirty value';
        expect(getFormSnapshot(form)).not.toBe(initialSnapshot);

        const hideEvent = new Event('hide.bs.modal', { cancelable: true });
        host.dispatchEvent(hideEvent);
        expect(hideEvent.defaultPrevented).toBe(true);

        const confirm = host.querySelector('.workspace-discard-confirm');
        expect(confirm.classList.contains('is-visible')).toBe(true);

        const secondHideEvent = new Event('hide.bs.modal', { cancelable: true });
        host.dispatchEvent(secondHideEvent);
        expect(secondHideEvent.defaultPrevented).toBe(true);
        expect(confirm.classList.contains('is-visible')).toBe(false);

        const thirdHideEvent = new Event('hide.bs.modal', { cancelable: true });
        host.dispatchEvent(thirdHideEvent);
        expect(thirdHideEvent.defaultPrevented).toBe(true);
        expect(confirm.classList.contains('is-visible')).toBe(true);
        expect(confirm.querySelectorAll('[data-workspace-discard-apply]').length).toBe(1);
    });

    it('aborts listeners when the modal is hidden', () => {
        const host = document.querySelector('[data-workspace-modal-host]');
        const modalContent = host.querySelector('[data-workspace-modal-content]');
        modalContent.innerHTML = '<form data-workspace-action-form="remark"><textarea name="body"></textarea></form>';

        initWorkspaceDialogShell(host, modalContent);
        host.dispatchEvent(new Event('hidden.bs.modal'));

        const hideEvent = new Event('hide.bs.modal', { cancelable: true });
        host.dispatchEvent(hideEvent);

        expect(hideEvent.defaultPrevented).toBe(false);
    });
});
