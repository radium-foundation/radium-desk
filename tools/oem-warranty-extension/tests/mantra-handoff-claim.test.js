import { describe, expect, it, vi } from 'vitest';
import {
    claimHandoffWithRetry,
    isRetriableHandoffClaimError,
    MANTRA_CLAIM_MAX_WAIT_MS,
} from '../src/lib/mantra-handoff-claim.js';

describe('isRetriableHandoffClaimError', () => {
    it('retries only transient no-handoff', () => {
        expect(isRetriableHandoffClaimError('no-handoff')).toBe(true);
        expect(isRetriableHandoffClaimError('claim-origin-mismatch')).toBe(false);
        expect(isRetriableHandoffClaimError('invalid-claim')).toBe(false);
    });
});

describe('claimHandoffWithRetry', () => {
    it('succeeds after transient no-handoff retries', async () => {
        let calls = 0;
        const sendClaim = vi.fn(async () => {
            calls += 1;
            if (calls < 3) {
                return { ok: false, error: 'no-handoff' };
            }
            return { ok: true, serial: '6540662' };
        });

        const now = vi.fn(() => 1_000);
        const sleep = vi.fn(async () => {});

        const result = await claimHandoffWithRetry(sendClaim, {
            oem: 'mantra',
            deadlineMs: MANTRA_CLAIM_MAX_WAIT_MS,
            now,
            sleep,
        });

        expect(result).toEqual({ ok: true, serial: '6540662' });
        expect(sendClaim).toHaveBeenCalledTimes(3);
    });

    it('stops immediately on permanent claim failure', async () => {
        const sendClaim = vi.fn(async () => ({ ok: false, error: 'claim-origin-mismatch' }));

        const result = await claimHandoffWithRetry(sendClaim, {
            oem: 'mantra',
            now: () => 0,
            sleep: async () => {},
        });

        expect(result).toEqual({ ok: false, error: 'claim-origin-mismatch' });
        expect(sendClaim).toHaveBeenCalledTimes(1);
    });

    it('exhausts retries and returns no-handoff', async () => {
        const sendClaim = vi.fn(async () => ({ ok: false, error: 'no-handoff' }));
        let clock = 0;
        const now = () => clock;
        const sleep = vi.fn(async (ms) => {
            clock += ms;
        });

        const result = await claimHandoffWithRetry(sendClaim, {
            oem: 'mantra',
            deadlineMs: 500,
            now,
            sleep,
        });

        expect(result).toEqual({ ok: false, error: 'no-handoff' });
        expect(sendClaim.mock.calls.length).toBeGreaterThan(1);
    });
});