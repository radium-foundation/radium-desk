import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    SESSION_REASONS,
    createWorkspaceSession,
    getWorkspaceSession,
    resetWorkspaceSession,
} from '../../../resources/js/workspace/session';

describe('createWorkspaceSession', () => {
    let session;

    beforeEach(() => {
        session = createWorkspaceSession();
    });

    it('tracks acquire, release, isActive, and getActiveReasons', () => {
        expect(session.isActive()).toBe(false);
        expect(session.getActiveReasons()).toEqual([]);

        session.acquire('workspace-modal');
        expect(session.isActive()).toBe(true);
        expect(session.isActive('workspace-modal')).toBe(true);
        expect(session.isActive('quick-create')).toBe(false);
        expect(session.getActiveReasons()).toEqual(['workspace-modal']);

        session.release('workspace-modal');
        expect(session.isActive()).toBe(false);
        expect(session.getActiveReasons()).toEqual([]);
    });

    it('ignores unsupported reasons', () => {
        session.acquire('unsupported-reason');
        expect(session.isActive()).toBe(false);
    });

    it('tracks locked incident ids from inline and bulk reasons', () => {
        session.acquire('inline-transaction', { incidentId: 12 });
        expect(session.getLockedIncidentIds()).toEqual([12]);

        session.acquire('bulk-selection', { incidentIds: [3, 4] });
        expect(session.getLockedIncidentIds()).toEqual([12, 3, 4]);

        session.release('inline-transaction');
        expect(session.getLockedIncidentIds()).toEqual([3, 4]);
    });

    it('fires onIdle callbacks when the last reason is released', () => {
        const onIdle = vi.fn();

        session.onIdle(onIdle);
        session.acquire('quick-create');
        session.acquire('workspace-modal');

        expect(onIdle).not.toHaveBeenCalled();

        session.release('quick-create');
        expect(onIdle).not.toHaveBeenCalled();

        session.release('workspace-modal');
        expect(onIdle).toHaveBeenCalledTimes(1);
    });

    it('supports unsubscribing idle callbacks', () => {
        const onIdle = vi.fn();
        const unsubscribe = session.onIdle(onIdle);

        session.acquire('quick-create');
        unsubscribe();
        session.release('quick-create');

        expect(onIdle).not.toHaveBeenCalled();
    });

    it('exposes all supported session reasons', () => {
        expect(SESSION_REASONS).toEqual([
            'workspace-modal',
            'inline-transaction',
            'bulk-selection',
            'quick-create',
            'notification-dropdown',
        ]);
    });
});

describe('getWorkspaceSession', () => {
    afterEach(() => {
        resetWorkspaceSession();
    });

    it('returns a shared singleton instance', () => {
        expect(getWorkspaceSession()).toBe(getWorkspaceSession());
    });
});
