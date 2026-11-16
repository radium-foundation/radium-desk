import { describe, expect, it } from 'vitest';
import {
    normalizeRdSubscriptionExpiryYmd,
    proposeRdSubscriptionExpiryFromMantraDraft,
} from '../../resources/js/dashboard-oem-rd-expiry-prefill';
import {
    getOemInlineCompletionContext,
    registerOemInlineCompletionFromSession,
    clearOemInlineCompletionContext,
} from '../../resources/js/dashboard-oem-inline-completion';

describe('normalizeRdSubscriptionExpiryYmd', () => {
    it('accepts ISO Y-m-d strings', () => {
        expect(normalizeRdSubscriptionExpiryYmd(' 2028-02-05 ')).toBe('2028-02-05');
    });

    it('rejects non-ISO values', () => {
        expect(normalizeRdSubscriptionExpiryYmd('05/02/2028')).toBeNull();
        expect(normalizeRdSubscriptionExpiryYmd('')).toBeNull();
    });
});

describe('proposeRdSubscriptionExpiryFromMantraDraft', () => {
    it('uses rd_expiry_date only for Mantra', () => {
        expect(proposeRdSubscriptionExpiryFromMantraDraft({
            oem: 'mantra',
            rd_expiry_date: '2028-02-05',
            warranty_expiry_date: '2027-02-05',
        })).toBe('2028-02-05');
    });

    it('never substitutes warranty expiry for RD expiry', () => {
        expect(proposeRdSubscriptionExpiryFromMantraDraft({
            oem: 'mantra',
            warranty_expiry_date: '2027-02-05',
        })).toBeNull();
    });

    it('does not prefill from Morpho drafts', () => {
        expect(proposeRdSubscriptionExpiryFromMantraDraft({
            oem: 'morpho',
            fields: { expiry_date: '2028-02-05' },
        })).toBeNull();
    });
});

describe('registerOemInlineCompletionFromSession', () => {
    it('stores proposed RD expiry in memory without persisting', () => {
        clearOemInlineCompletionContext(42);
        const registered = registerOemInlineCompletionFromSession(42, {
            public_id: 'sess-42',
            status: 'draft_ready',
            draft: {
                oem: 'mantra',
                rd_expiry_date: '2026-06-01',
            },
        }, '/dashboard/service-cases');

        expect(registered).toBe(true);
        expect(getOemInlineCompletionContext(42)).toMatchObject({
            sessionPublicId: 'sess-42',
            proposedRdExpiryYmd: '2026-06-01',
        });
        clearOemInlineCompletionContext(42);
    });
});