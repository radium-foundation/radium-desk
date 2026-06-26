import { describe, expect, it } from 'vitest';
import {
    WorkspaceRequestError,
    handleWorkspaceError,
    isWorkspaceResponse,
    renderWorkspaceErrorHtml,
} from '../../../resources/js/workspace/error-handler';

describe('handleWorkspaceError', () => {
    it('returns workspace responses unchanged', () => {
        const payload = {
            success: false,
            message: 'Validation failed.',
        };

        expect(handleWorkspaceError(null, { ok: false, status: 422 }, payload)).toBe(payload);
        expect(isWorkspaceResponse(payload)).toBe(true);
    });

    it('maps network failures to a toast and inline error', () => {
        const result = handleWorkspaceError(new TypeError('Failed to fetch'));

        expect(result.success).toBe(false);
        expect(result.message).toContain('Unable to connect');
        expect(result.toast.variant).toBe('danger');
        expect(result.inline.html).toContain('data-workspace-error');
    });

    it('maps timeout failures to a toast and inline error', () => {
        const error = new WorkspaceRequestError('timeout', null, 'The request timed out. Please try again.');
        const result = handleWorkspaceError(error);

        expect(result.message).toContain('timed out');
        expect(result.inline.html).toBe(renderWorkspaceErrorHtml(result.message));
    });

    it.each([
        [403, 'permission'],
        [404, 'found'],
        [500, 'server'],
    ])('maps HTTP %s responses to user-facing errors', (status, expectedFragment) => {
        const result = handleWorkspaceError(null, { ok: false, status }, null);

        expect(result.success).toBe(false);
        expect(result.message.toLowerCase()).toContain(expectedFragment);
        expect(result.toast.show).toBe(true);
    });

    it('extracts the first validation message for 422 responses', () => {
        const result = handleWorkspaceError(null, { ok: false, status: 422 }, {
            errors: {
                assigned_to_user_id: ['Select an admin.'],
            },
        });

        expect(result.message).toBe('Select an admin.');
    });
});
