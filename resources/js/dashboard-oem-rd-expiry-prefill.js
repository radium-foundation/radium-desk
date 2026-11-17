/**
 * Proposed RD subscription expiry from Mantra OEM capture (informational draft only — not persisted until admin tick).
 */

export const OEM_RD_EXPIRY_INELIGIBLE_FOR_AW_MESSAGE =
    'Mantra RD expiry is too near or has passed — Service Reference was not filled. Enter a valid AW reference manually.';

export const OEM_RD_EXPIRY_ELIGIBILITY_MIN_CALENDAR_DAYS_AFTER_TODAY = 3;

const ASIA_KOLKATA = 'Asia/Kolkata';

const isValidCalendarYmd = (ymd) => {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(ymd);
    if (!match) {
        return false;
    }

    const year = Number(match[1]);
    const month = Number(match[2]);
    const day = Number(match[3]);
    if (!Number.isFinite(year) || !Number.isFinite(month) || !Number.isFinite(day)) {
        return false;
    }

    const probe = new Date(Date.UTC(year, month - 1, day));

    return probe.getUTCFullYear() === year
        && probe.getUTCMonth() === month - 1
        && probe.getUTCDate() === day;
};

export const normalizeRdSubscriptionExpiryYmd = (value) => {
    if (typeof value !== 'string') {
        return null;
    }

    const trimmed = value.trim();
    if (!/^\d{4}-\d{2}-\d{2}$/.test(trimmed) || !isValidCalendarYmd(trimmed)) {
        return null;
    }

    return trimmed;
};

/**
 * @param {Record<string, unknown>|null|undefined} draft Normalized dashboard session draft view
 * @returns {string|null} ISO date (Y-m-d) for HTML date input, or null
 */
/**
 * @param {string|null|undefined} ymd ISO date Y-m-d from Mantra rd_expiry_date
 * @returns {string|null} Human-readable d/m/Y for Service Reference input (e.g. 29/01/2027)
 */
export const formatRdExpiryYmdForServiceReferenceInput = (ymd) => {
    const normalized = normalizeRdSubscriptionExpiryYmd(typeof ymd === 'string' ? ymd : null);
    if (!normalized) {
        return null;
    }

    const [year, month, day] = normalized.split('-');

    return `${day}/${month}/${year}`;
};

/**
 * @param {string|null|undefined} ymd ISO date Y-m-d from Mantra rd_expiry_date
 * @returns {string|null} AW-DD-MM-YYYY (e.g. AW-08-07-2026 from 2026-07-08)
 */
export const formatRdExpiryYmdAsAwServiceReference = (ymd) => {
    const normalized = normalizeRdSubscriptionExpiryYmd(typeof ymd === 'string' ? ymd : null);
    if (!normalized) {
        return null;
    }

    const [year, month, day] = normalized.split('-');

    return `AW-${day}-${month}-${year}`;
};

const ymdToUtcCalendarDate = (ymd) => {
    const normalized = normalizeRdSubscriptionExpiryYmd(ymd);
    if (!normalized) {
        return null;
    }

    const [year, month, day] = normalized.split('-').map((part) => Number(part));
    if (!Number.isFinite(year) || !Number.isFinite(month) || !Number.isFinite(day)) {
        return null;
    }

    return new Date(Date.UTC(year, month - 1, day));
};

const utcCalendarDateToYmd = (date) => {
    const year = date.getUTCFullYear();
    const month = String(date.getUTCMonth() + 1).padStart(2, '0');
    const day = String(date.getUTCDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
};

/**
 * @param {string} ymd ISO Y-m-d
 * @param {number} days signed calendar-day offset
 * @returns {string|null}
 */
export const addCalendarDaysToYmd = (ymd, days) => {
    const base = ymdToUtcCalendarDate(ymd);
    if (!base) {
        return null;
    }

    const shifted = new Date(base.getTime());
    shifted.setUTCDate(shifted.getUTCDate() + days);

    return utcCalendarDateToYmd(shifted);
};

/**
 * @param {Date} [referenceDate] defaults to now
 * @returns {string} today as Y-m-d in Asia/Kolkata
 */
export const getTodayYmdInAsiaKolkata = (referenceDate = new Date()) => {
    const formatter = new Intl.DateTimeFormat('en-CA', {
        timeZone: ASIA_KOLKATA,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    });

    return formatter.format(referenceDate);
};

/**
 * Eligibility: expiry calendar date must be on or after today (IST) + 3 calendar days.
 *
 * @param {string|null|undefined} expiryYmd
 * @param {string|null|undefined} todayYmd optional inject for tests
 */
export const isMantraRdExpiryEligibleForAwPrefill = (expiryYmd, todayYmd = null) => {
    const normalizedExpiry = normalizeRdSubscriptionExpiryYmd(
        typeof expiryYmd === 'string' ? expiryYmd : null,
    );
    if (!normalizedExpiry) {
        return false;
    }

    const today = todayYmd ?? getTodayYmdInAsiaKolkata();
    const minAllowed = addCalendarDaysToYmd(today, OEM_RD_EXPIRY_ELIGIBILITY_MIN_CALENDAR_DAYS_AFTER_TODAY);
    if (!minAllowed) {
        return false;
    }

    return normalizedExpiry.localeCompare(minAllowed) >= 0;
};

/**
 * @param {string|null|undefined} expiryYmd
 * @param {string|null|undefined} todayYmd optional inject for tests
 * @returns {string|null} AW reference when eligible; null when ineligible or invalid
 */
export const buildEligibleAwServiceReferenceFromMantraRdExpiry = (expiryYmd, todayYmd = null) => {
    if (!isMantraRdExpiryEligibleForAwPrefill(expiryYmd, todayYmd)) {
        return null;
    }

    return formatRdExpiryYmdAsAwServiceReference(expiryYmd);
};

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