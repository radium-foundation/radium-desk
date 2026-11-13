/**
 * @typedef {'mantra' | 'morpho'} OemVendor
 */

/**
 * @param {unknown} oem
 * @returns {oem is OemVendor}
 */
export function isOemVendor(oem) {
    return oem === 'mantra' || oem === 'morpho';
}

/**
 * @param {string} serial
 * @returns {boolean}
 */
export function isValidHandoffSerial(serial) {
    const normalized = String(serial).trim();
    if (normalized === '' || normalized.length > 32) {
        return false;
    }

    return /^[A-Za-z0-9]+$/.test(normalized);
}

/**
 * @param {string | undefined} url
 * @param {OemVendor} oem
 * @returns {boolean}
 */
export function isExpectedOemDestinationUrl(url, oem) {
    if (!url || typeof url !== 'string') {
        return false;
    }

    if (oem === 'mantra') {
        return url.startsWith('https://servico.mantratecapp.com/MasterForms/RDWarrantyCheck.aspx');
    }

    if (oem === 'morpho') {
        return url.startsWith('https://rdserviceonline.com/pages/172iserialvalidatenumber');
    }

    return false;
}
