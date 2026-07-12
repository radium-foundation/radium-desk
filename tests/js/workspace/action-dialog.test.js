import { beforeEach, describe, expect, it } from 'vitest';
import { initActionDialog } from '../../../resources/js/workspace/action-dialog';

const buildFormHtml = ({
    selectedAction = 'assign',
    capabilities = ['assign', 'escalate', 'close'],
} = {}) => {
    const segments = capabilities.map((action) => `
        <button type="button"
                class="workspace-action-segment workspace-action-segment--${action} ${selectedAction === action ? 'is-active' : ''}"
                data-workspace-action-card="${action}">
            ${action}
        </button>
    `).join('');

    const descriptions = capabilities.map((action) => `
        <p class="workspace-action-description ${selectedAction === action ? '' : 'd-none'}"
           data-workspace-action-description="${action}">
            Description for ${action}
        </p>
    `).join('');

    const notifyNotes = ['assign', 'escalate', 'close']
        .filter((action) => capabilities.includes(action))
        .map((action) => `
        <p class="workspace-action-notify-note ${selectedAction === action ? '' : 'd-none'}"
           data-workspace-action-notify-note="${action}">
            Notify note for ${action}
        </p>
    `).join('');

    const panels = ['assign', 'close']
        .filter((action) => capabilities.includes(action))
        .map((action) => `
        <div class="workspace-action-panel ${selectedAction === action ? '' : 'd-none'}"
             data-workspace-action-panel="${action}">
            <input type="text" name="${action}_field" ${selectedAction === action ? '' : 'disabled'}>
        </div>
    `).join('');

    return `
        <form data-workspace-action-dialog data-workspace-action-form="action">
            <input type="hidden" name="action_type" value="${selectedAction}" data-workspace-action-type-input>
            <div class="workspace-action-segments">${segments}</div>
            <div class="workspace-action-descriptions">${descriptions}</div>
            ${panels}
            <div class="workspace-action-notify-notes">${notifyNotes}</div>
            <textarea data-workspace-action-remark rows="3"></textarea>
            <button type="submit" class="workspace-action-submit workspace-action-submit--${selectedAction}" data-workspace-action-submit>
                Assign Engineer
            </button>
        </form>
    `;
};

describe('initActionDialog', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('switches action segments and updates submit button label and accent', () => {
        document.body.innerHTML = buildFormHtml();
        const form = document.querySelector('[data-workspace-action-dialog]');

        initActionDialog(document);

        form.querySelector('[data-workspace-action-card="close"]').click();

        expect(form.querySelector('[data-workspace-action-type-input]').value).toBe('close');
        expect(form.querySelector('[data-workspace-action-submit]').textContent).toBe('Close Case');
        expect(form.querySelector('[data-workspace-action-submit]').classList.contains('workspace-action-submit--close')).toBe(true);
        expect(form.querySelector('[data-workspace-action-panel="close"]').classList.contains('d-none')).toBe(false);
        expect(form.querySelector('[name="close_field"]').disabled).toBe(false);
        expect(form.querySelector('[name="assign_field"]').disabled).toBe(true);
    });

    it('updates description and notification note visibility for the selected action', () => {
        document.body.innerHTML = buildFormHtml();

        initActionDialog(document);
        document.querySelector('[data-workspace-action-card="escalate"]').click();

        expect(document.querySelector('[data-workspace-action-description="escalate"]').classList.contains('d-none')).toBe(false);
        expect(document.querySelector('[data-workspace-action-description="assign"]').classList.contains('d-none')).toBe(true);
        expect(document.querySelector('[data-workspace-action-notify-note="escalate"]').classList.contains('d-none')).toBe(false);
        expect(document.querySelector('[data-workspace-action-notify-note="assign"]').classList.contains('d-none')).toBe(true);
    });

    it('updates remark placeholder for the selected action', () => {
        document.body.innerHTML = buildFormHtml();
        const remark = document.querySelector('[data-workspace-action-remark]');

        initActionDialog(document);
        document.querySelector('[data-workspace-action-card="escalate"]').click();

        expect(remark.placeholder).toBe('Explain why this requires escalation…');
    });

    it('does not override the server-selected initial action', () => {
        document.body.innerHTML = buildFormHtml({ selectedAction: 'assign' });

        initActionDialog(document);

        expect(document.querySelector('[data-workspace-action-type-input]').value).toBe('assign');
        expect(document.querySelector('[data-workspace-action-card="assign"]').classList.contains('is-active')).toBe(true);
    });

    it('auto-grows the remark textarea on input', () => {
        document.body.innerHTML = buildFormHtml();
        const remark = document.querySelector('[data-workspace-action-remark]');
        remark.style.height = '48px';

        initActionDialog(document);
        remark.value = 'Line one\nLine two\nLine three\nLine four';
        remark.dispatchEvent(new Event('input'));

        expect(remark.style.height).not.toBe('48px');
    });
});
