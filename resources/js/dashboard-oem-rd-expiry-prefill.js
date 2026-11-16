/**
 * Proposed RD subscription expiry from Mantra OEM capture (informational draft only — not persisted until admin tick).
 */

export const normalizeRdSubscriptionExpiryYmd = (value) => {
    if (typeof value !== 'string') {
        return null;
    }

    const trimmed = value.trim();
    if (/^\d{4}-\d{2}-\d{2}$/.test(trimmed)) {
        return trimmed;
    }

    return null;
};

/**
 * @param {Record<string, unknown>|null|undefined} draft Normalized dashboard session draft view
 * @returns {string|null} ISO date (Y-m-d) for HTML date input, or null
 */
export const proposeRdSubscriptionExpiryFromMantraDraft = (draft) => {
    if (!draft || typeof draft !== 'object') {
        return null;
    }

    const oem = typeof draft.oem === 'string' ? draft.oem : 'mantra';
    if (oem !== 'mantra') {
        return null;
    }

    return normalizeRdSubscriptionExpiryYmd(
        typeof draft.rd_expiry_date === 'string' ? draft.rd_expiry_date : null,
    );
};