import { describe, expect, it, vi } from 'vitest';
import { HandoffStore } from '../src/lib/handoff-store.js';
import { handleHandoffRequest } from '../src/lib/handoff-request-handler.js';

const deskSender = { tab: { url: 'https://desk.radiumbox.com/dashboard' } };

describe('handleHandoffRequest', () => {
    it('returns ok immediately before the OEM tab is created', async () => {
        const store = new HandoffStore();
        let resolveOpen;
        const openOemTab = vi.fn(() => new Promise((resolve) => {
            resolveOpen = resolve;
        }));
        const setSessionMeta = vi.fn();

        const promise = Promise.resolve().then(() => handleHandoffRequest(
            deskSender,
            { oem: 'mantra', serial: 'abc123' },
            { store, setSessionMeta, openOemTab },
        ));

        const result = await promise;
        expect(result.ok).toBe(true);
        expect(result.handoffId).toBeTypeOf('string');
        expect(openOemTab).toHaveBeenCalledOnce();
        expect(setSessionMeta).not.toHaveBeenCalled();

        resolveOpen({ id: 99 });
        await vi.waitFor(() => {
            expect(setSessionMeta).toHaveBeenCalledWith(99, expect.objectContaining({ oem: 'mantra' }));
        });

        const claimed = store.consume({ tabId: 99, oem: 'mantra' });
        expect(claimed?.serial).toBe('ABC123');
    });

    it('allows claim before bindTab via pending adoption', async () => {
        const store = new HandoffStore();
        let resolveOpen;
        const openOemTab = vi.fn(() => new Promise((resolve) => {
            resolveOpen = resolve;
        }));

        const result = handleHandoffRequest(
            deskSender,
            { oem: 'mantra', serial: 'latebind' },
            { store, setSessionMeta: vi.fn(), openOemTab },
        );

        expect(result.ok).toBe(true);

        const earlyClaim = store.consume({ tabId: 42, oem: 'mantra' });
        expect(earlyClaim?.serial).toBe('LATEBIND');

        resolveOpen({ id: 42 });
        await vi.waitFor(() => {
            expect(openOemTab).toHaveBeenCalled();
        });
    });

    it('discards the handoff when tab creation fails', async () => {
        const store = new HandoffStore();
        const openOemTab = vi.fn(async () => undefined);

        const result = handleHandoffRequest(
            deskSender,
            { oem: 'morpho', serial: 'm001' },
            { store, setSessionMeta: vi.fn(), openOemTab },
        );

        expect(result.ok).toBe(true);
        await vi.waitFor(() => {
            expect(openOemTab).toHaveBeenCalled();
        });

        expect(store.consume({ tabId: 1, oem: 'morpho' })).toBeNull();
    });

    it('rejects invalid payloads without opening a tab', () => {
        const store = new HandoffStore();
        const openOemTab = vi.fn();

        const result = handleHandoffRequest(
            deskSender,
            { oem: 'mantra', serial: '' },
            { store, setSessionMeta: vi.fn(), openOemTab },
        );

        expect(result).toEqual({ ok: false, error: 'invalid-payload' });
        expect(openOemTab).not.toHaveBeenCalled();
    });
});