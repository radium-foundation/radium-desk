import { describe, expect, it } from 'vitest';
import {
    addCalendarDaysToYmd,
    buildEligibleAwServiceReferenceFromMantraRdExpiry,
    formatRdExpiryYmdAsAwServiceReference,
    formatRdExpiryYmdForServiceReferenceInput,
    getTodayYmdInAsiaKolkata,
    isMantraRdExpiryEligibleForAwPrefill,
    normalizeRdSubscriptionExpiryYmd,
    proposeMorphoSubscriptionExpiryFromDraft,
    proposeOemSubscriptionExpiryFromDraft,
    proposeRdSubscriptionExpiryFromMantraDraft,
} from '../../resources/js/dashboard-oem-rd-expiry-prefill';
import {
    getOemInlineCompletionContext,
    registerOemInlineCompletionFromSession,
    clearOemInlineCompletionContext,
} from '../../resources/js/dashboard-oem-inline-completion';

const TODAY = '2026-11-17';

describe('formatRdExpiryYmdAsAwServiceReference', () => {
    it('formats Y-m-d as AW-DD-MM-YYYY', () => {
        expect(formatRdExpiryYmdAsAwServiceReference('2026-07-08')).toBe('AW-08-07-2026');
    });

    it('returns null for invalid input', () => {
        expect(formatRdExpiryYmdAsAwServiceReference('07/08/2026')).toBeNull();
    });
});

describe('normalizeRdSubscriptionExpiryYmd', () => {
    it('accepts ISO Y-m-d strings', () => {
        expect(normalizeRdSubscriptionExpiryYmd(' 2028-02-05 ')).toBe('2028-02-05');
    });

    it('rejects non-ISO values', () => {
        expect(normalizeRdSubscriptionExpiryYmd('05/02/2028')).toBeNull();
        expect(normalizeRdSubscriptionExpiryYmd('')).toBeNull();
    });
});

describe('formatRdExpiryYmdForServiceReferenceInput', () => {
    it('formats Y-m-d as d/m/Y', () => {
        expect(formatRdExpiryYmdForServiceReferenceInput('2027-01-29')).toBe('29/01/2027');
    });

    it('returns null for invalid input', () => {
        expect(formatRdExpiryYmdForServiceReferenceInput(null)).toBeNull();
    });
});

describe('addCalendarDaysToYmd', () => {
    it('adds calendar days across month boundaries', () => {
        expect(addCalendarDaysToYmd('2026-01-29', 3)).toBe('2026-02-01');
    });

    it('adds calendar days across year boundaries', () => {
        expect(addCalendarDaysToYmd('2025-12-30', 3)).toBe('2026-01-02');
    });

    it('handles leap day', () => {
        expect(addCalendarDaysToYmd('2024-02-27', 3)).toBe('2024-03-01');
    });
});

describe('isMantraRdExpiryEligibleForAwPrefill', () => {
    const thirdDay = addCalendarDaysToYmd(TODAY, 3);
    const tomorrow = addCalendarDaysToYmd(TODAY, 1);
    const dayAfter = addCalendarDaysToYmd(TODAY, 2);

    it('rejects today, tomorrow, and day-after-tomorrow', () => {
        expect(isMantraRdExpiryEligibleForAwPrefill(TODAY, TODAY)).toBe(false);
        expect(isMantraRdExpiryEligibleForAwPrefill(tomorrow, TODAY)).toBe(false);
        expect(isMantraRdExpiryEligibleForAwPrefill(dayAfter, TODAY)).toBe(false);
    });

    it('accepts third calendar day after today', () => {
        expect(isMantraRdExpiryEligibleForAwPrefill(thirdDay, TODAY)).toBe(true);
    });

    it('accepts later dates', () => {
        expect(isMantraRdExpiryEligibleForAwPrefill('2028-02-05', TODAY)).toBe(true);
    });

    it('rejects past dates', () => {
        expect(isMantraRdExpiryEligibleForAwPrefill('2025-01-01', TODAY)).toBe(false);
    });

    it('rejects invalid or missing dates', () => {
        expect(isMantraRdExpiryEligibleForAwPrefill(null, TODAY)).toBe(false);
        expect(isMantraRdExpiryEligibleForAwPrefill('2026-13-01', TODAY)).toBe(false);
    });
});

describe('buildEligibleAwServiceReferenceFromMantraRdExpiry', () => {
    it('returns AW reference only when eligible', () => {
        const eligible = addCalendarDaysToYmd(TODAY, 3);
        expect(buildEligibleAwServiceReferenceFromMantraRdExpiry(eligible, TODAY)).toBe(
            formatRdExpiryYmdAsAwServiceReference(eligible),
        );
        expect(buildEligibleAwServiceReferenceFromMantraRdExpiry(TODAY, TODAY)).toBeNull();
    });
});

describe('getTodayYmdInAsiaKolkata', () => {
    it('uses Asia/Kolkata calendar date at day boundary', () => {
        const lateUtc = new Date('2026-11-16T20:30:00.000Z');
        expect(getTodayYmdInAsiaKolkata(lateUtc)).toBe('2026-11-17');
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

describe('proposeMorphoSubscriptionExpiryFromDraft', () => {
    it('uses fields.expiry_date only when already whitelisted', () => {
        expect(proposeMorphoSubscriptionExpiryFromDraft({
            oem: 'morpho',
            whitelist_qualification: 'already_whitelisted',
            fields: { expiry_date: '2028-03-15', warranty_expiry_date: '2027-01-01' },
        })).toBe('2028-03-15');
    });

    it('rejects non-whitelisted Morpho drafts', () => {
        expect(proposeMorphoSubscriptionExpiryFromDraft({
            oem: 'morpho',
            whitelist_qualification: 'valid_not_whitelisted',
            fields: { expiry_date: '2028-03-15' },
        })).toBeNull();
    });

    it('never uses Mantra rd_expiry_date on Morpho oem', () => {
        expect(proposeMorphoSubscriptionExpiryFromDraft({
            oem: 'morpho',
            whitelist_qualification: 'already_whitelisted',
            rd_expiry_date: '2028-03-15',
            fields: {},
        })).toBeNull();
    });
});

describe('proposeOemSubscriptionExpiryFromDraft', () => {
    it('isolates Mantra and Morpho field sources', () => {
        expect(proposeOemSubscriptionExpiryFromDraft({
            oem: 'mantra',
            rd_expiry_date: '2028-02-05',
        }, 'mantra')).toBe('2028-02-05');

        expect(proposeOemSubscriptionExpiryFromDraft({
            oem: 'morpho',
            whitelist_qualification: 'already_whitelisted',
            fields: { expiry_date: '2028-04-01' },
        }, 'morpho')).toBe('2028-04-01');
    });
});

describe('registerOemInlineCompletionFromSession', () => {
    it('stores proposed RD expiry in memory without persisting', () => {
        clearOemInlineCompletionContext(42);
        const eligibleDate = '2030-06-01';
        const registered = registerOemInlineCompletionFromSession(42, {
            public_id: 'sess-42',
            status: 'draft_ready',
            draft: {
                oem: 'mantra',
                rd_expiry_date: eligibleDate,
            },
        }, '/dashboard/service-cases');

        expect(registered).toBe(true);
        expect(getOemInlineCompletionContext(42)).toMatchObject({
            sessionPublicId: 'sess-42',
            proposedRdExpiryYmd: eligibleDate,
            proposedRdExpiryDisplay: formatRdExpiryYmdAsAwServiceReference(eligibleDate),
        });
        clearOemInlineCompletionContext(42);
    });

    it('registers Morpho draft when already whitelisted with expiry_date', () => {
        clearOemInlineCompletionContext(43);
        const eligibleDate = '2030-08-20';
        const registered = registerOemInlineCompletionFromSession(43, {
            public_id: 'sess-43',
            status: 'draft_ready',
            oem: 'morpho',
            draft: {
                oem: 'morpho',
                whitelist_qualification: 'already_whitelisted',
                fields: { expiry_date: eligibleDate },
            },
        }, '/dashboard/service-cases');

        expect(registered).toBe(true);
        expect(getOemInlineCompletionContext(43)).toMatchObject({
            oem: 'morpho',
            proposedRdExpiryYmd: eligibleDate,
        });
        clearOemInlineCompletionContext(43);
    });

    it('does not register Morpho draft when not already whitelisted', () => {
        clearOemInlineCompletionContext(44);
        const registered = registerOemInlineCompletionFromSession(44, {
            public_id: 'sess-44',
            status: 'draft_ready',
            oem: 'morpho',
            draft: {
                oem: 'morpho',
                whitelist_qualification: 'valid_not_whitelisted',
                fields: { expiry_date: '2030-08-20' },
            },
        }, '/dashboard/service-cases');

        expect(registered).toBe(false);
        expect(getOemInlineCompletionContext(44)).toBeNull();
        clearOemInlineCompletionContext(44);
    });
});