/** @typedef {'mantra' | 'morpho'} OemVendor */

export const HANDOFF_TTL_MS = 60_000;

/** @type {Record<OemVendor, string>} */
export const OEM_PAGE_URLS = {
    mantra: 'https://servico.mantratecapp.com/MasterForms/RDWarrantyCheck.aspx',
    morpho: 'https://rdserviceonline.com/pages/172iserialvalidatenumber',
};

/**
 * Desk origins allowed to initiate handoffs (production + local dev for fixture tests only in unpacked builds).
 * @type {string[]}
 */
export const ALLOWED_DESK_ORIGIN_PREFIXES = [
    'https://desk.radiumbox.com/',
    'http://127.0.0.1:',
    'http://localhost:',
];

export const MSG = {
    HANDOFF_REQUEST: 'OEM_HANDOFF_REQUEST',
    HANDOFF_CLAIM: 'OEM_HANDOFF_CLAIM',
    HANDOFF_CLAIM_RESULT: 'OEM_HANDOFF_CLAIM_RESULT',
};
