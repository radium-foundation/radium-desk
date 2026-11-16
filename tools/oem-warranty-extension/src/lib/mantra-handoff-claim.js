import { MSG } from './constants.js';

export const MANTRA_CLAIM_MAX_WAIT_MS = 8_000;
export const MANTRA_CLAIM_INITIAL_DELAY_MS = 40;
export const MANTRA_CLAIM_MAX_DELAY_MS = 250;

/** @type {ReadonlySet<string>} */
export const RETRIABLE_HANDOFF_CLAIM_ERRORS = new Set(['no-handoff']);

/**
 * @param {string | undefined} error
 * @returns {boolean}
 */
export function isRetriableHandoffClaimError(error) {
    return typeof error === 'string' && RETRIABLE_HANDOFF_CLAIM_ERRORS.has(error);
}

/**
 * @param {(payload: { type: string, oem: string }) => Promise<{ ok?: boolean, error?: string, serial?: string }>} sendClaim
 * @param {{ oem: string, deadlineMs?: number, now?: () => number, sleep?: (ms: number) => Promise<void> }} options
 */
export async function claimHandoffWithRetry(sendClaim, options) {
    const oem = options.oem;
    const now = options.now ?? (() => Date.now());
    const sleep = options.sleep ?? ((ms) => new Promise((resolve) => { setTimeout(resolve, ms); }));
    const deadline = now() + (options.deadlineMs ?? MANTRA_CLAIM_MAX_WAIT_MS);
    let delay = MANTRA_CLAIM_INITIAL_DELAY_MS;

    while (now() < deadline) {
        const response = await sendClaim({ type: MSG.HANDOFF_CLAIM, oem });

        if (response?.ok) {
            return response;
        }

        const error = response?.error ?? 'handoff-failed';
        if (!isRetriableHandoffClaimError(error)) {
            return { ok: false, error };
        }

        const remaining = deadline - now();
        if (remaining <= 0) {
            break;
        }

        await sleep(Math.min(delay, remaining, MANTRA_CLAIM_MAX_DELAY_MS));
        delay = Math.min(delay * 1.5, MANTRA_CLAIM_MAX_DELAY_MS);
    }

    return { ok: false, error: 'no-handoff' };
}