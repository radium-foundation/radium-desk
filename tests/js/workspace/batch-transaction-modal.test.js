import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createFragmentLoader } from '../../../resources/js/workspace/fragment-loader';
import { getWorkspaceSession, resetWorkspaceSession } from '../../../resources/js/workspace/session';

vi.mock('../../../resources/js/workspace/http', () => ({
    workspaceFetch: vi.fn(),
    workspaceFetchHeaders: vi.fn(() => ({})),
}));

import { workspaceFetch } from '../../../resources/js/workspace/http';

describe('batch transaction modal', () => {
    beforeEach(() => {
        resetWorkspaceSession();
        document.body.innerHTML = `
            <div data-workspace-modal-host>
                <div data-workspace-modal-content></div>
            </div>
        `;
        vi.clearAllMocks();
        window.bootstrap = {
            Modal: {
                getOrCreateInstance: vi.fn(() => ({
                    show: vi.fn(),
                })),
            },
        };
    });

    it('defers polling while batch modal is open via workspace session', async () => {
        const session = getWorkspaceSession();
        const host = document.querySelector('[data-workspace-modal-host]');

        host.addEventListener('shown.bs.modal', () => {
            session.acquire('workspace-modal');
        });

        workspaceFetch.mockResolvedValue({
            ok: true,
            text: async () => '<form data-workspace-action-form="batch-transaction"></form>',
        });

        const loader = createFragmentLoader({
            host,
            busyState: null,
            lifecycle: { run: vi.fn(async () => true) },
        });

        await loader.openBatchComponent('batch-transaction', [1, 2], 'dashboard');

        host.dispatchEvent(new Event('shown.bs.modal'));

        expect(session.isActive('workspace-modal')).toBe(true);
    });
});
