import { describe, expect, it, vi } from 'vitest';
import { createLifecycleRunner } from '../../../resources/js/workspace/lifecycle';

describe('createLifecycleRunner', () => {
    it('runs optional hooks and allows the action to continue', async () => {
        const beforeOpen = vi.fn();
        const lifecycle = createLifecycleRunner({ beforeOpen });

        await expect(lifecycle.run('beforeOpen', 1, 'assign', 'dashboard')).resolves.toBe(true);
        expect(beforeOpen).toHaveBeenCalledWith(1, 'assign', 'dashboard');
    });

    it('skips undefined hooks without failing', async () => {
        const lifecycle = createLifecycleRunner({});

        await expect(lifecycle.run('afterClose', document.createElement('div'))).resolves.toBe(true);
    });

    it('cancels the action when a hook returns false', async () => {
        const lifecycle = createLifecycleRunner({
            beforeSubmit: () => false,
        });

        await expect(lifecycle.run('beforeSubmit', document.createElement('form'))).resolves.toBe(false);
    });

    it('awaits async hooks', async () => {
        const afterSuccess = vi.fn(async () => {
            await Promise.resolve();
            return true;
        });
        const lifecycle = createLifecycleRunner({ afterSuccess });

        await lifecycle.run('afterSuccess', { success: true }, document.createElement('div'));
        expect(afterSuccess).toHaveBeenCalledTimes(1);
    });
});
