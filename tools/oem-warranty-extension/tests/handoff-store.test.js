import { describe, expect, it, vi, afterEach } from 'vitest';
import { HandoffStore } from '../src/lib/handoff-store.js';

describe('HandoffStore', () => {
    afterEach(() => {
        vi.useRealTimers();
    });

    it('consumes handoff once per tab', () => {
        const store = new HandoffStore({ ttlMs: 60_000 });
        const { handoffId } = store.create({ oem: 'mantra', serial: 'SYN-001' });
        store.bindTab(handoffId, 42);

        const first = store.consume({ tabId: 42, oem: 'mantra' });
        const second = store.consume({ tabId: 42, oem: 'mantra' });

        expect(first).toEqual({ serial: 'SYN-001' });
        expect(second).toBeNull();
    });

    it('rejects cross-tab claim', () => {
        const store = new HandoffStore();
        const { handoffId } = store.create({ oem: 'morpho', serial: 'SYN-002' });
        store.bindTab(handoffId, 1);

        expect(store.consume({ tabId: 2, oem: 'morpho' })).toBeNull();
    });

    it('expires handoffs after ttl', () => {
        vi.useFakeTimers();
        const store = new HandoffStore({ ttlMs: 1_000 });
        const { handoffId } = store.create({ oem: 'mantra', serial: 'SYN-003' });
        store.bindTab(handoffId, 9);

        vi.advanceTimersByTime(1_001);
        expect(store.consume({ tabId: 9, oem: 'mantra' })).toBeNull();
    });

    it('rejects oem mismatch on consume', () => {
        const store = new HandoffStore();
        const { handoffId } = store.create({ oem: 'mantra', serial: 'SYN-004' });
        store.bindTab(handoffId, 5);

        expect(store.consume({ tabId: 5, oem: 'morpho' })).toBeNull();
    });

    it('discards data on consume without retaining serial', () => {
        const store = new HandoffStore();
        const { handoffId } = store.create({ oem: 'mantra', serial: 'SYN-005' });
        store.bindTab(handoffId, 7);
        store.consume({ tabId: 7, oem: 'mantra' });

        expect(store.byId.size).toBe(0);
        expect(store.idByTabId.size).toBe(0);
    });
});
