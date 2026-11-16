/**
 * Parse visible Morpho validation DOM after administrator-triggered OEM checks only.
 * Does not invoke Selectize, blur, or form submission.
 *
 * @param {Document} doc
 */
export function readMorphoResultFields(doc) {
    const panel = doc.querySelector('#morpho-validation-panel, #divValidationResult, .validation-result-panel');
    const serial =
        doc.querySelector('#lblSerial, [data-oem-serial], #device-serial-number1')?.value
        ?? doc.querySelector('#lblSerial, [data-oem-serial]')?.textContent?.trim()
        ?? '';

    const readCell = (selectors) => {
        for (const selector of selectors) {
            const el = doc.querySelector(selector);
            if (!el) {
                continue;
            }
            const text = el instanceof HTMLInputElement ? el.value : el.textContent;
            const value = text?.trim() ?? '';
            if (value !== '') {
                return value;
            }
        }

        return '';
    };

    return {
        serial_number: serial,
        device_validity: readCell(['#lblDeviceValidity', '[data-device-validity]', '.device-validity']),
        whitelist_status: readCell(['#lblWhitelistStatus', '[data-whitelist-status]', '.whitelist-status']),
        whitelist_date: readCell(['#lblWhitelistDate', '[data-whitelist-date]']),
        expiry_date: readCell(['#lblExpiryDate', '[data-expiry-date]', '.expiry-date']),
        validity_years: readCell(['#lblValidityYears', '[data-validity-years]']),
        device_type: readCell(['#lblDeviceType', '[data-device-type]', '.device-type']),
        panel_visible: Boolean(panel && panel instanceof HTMLElement && panel.offsetParent !== null),
    };
}

/**
 * @param {Document} doc
 * @returns {boolean}
 */
export function isMorphoResultVisible(doc) {
    const fields = readMorphoResultFields(doc);
    if (!fields.panel_visible) {
        const tbody = doc.querySelector('#tblDeviceResult tbody, table.device-result tbody');
        if (!tbody || tbody.querySelectorAll('tr').length === 0) {
            return false;
        }
    }

    return [fields.device_validity, fields.whitelist_status, fields.expiry_date, fields.device_type]
        .some((value) => value && value !== '');
}

/**
 * @param {ReturnType<typeof readMorphoResultFields>} fields
 * @returns {'found' | 'not_found' | 'inconclusive'}
 */
export function inferMorphoOutcome(fields) {
    const hasData = [fields.device_validity, fields.whitelist_status, fields.expiry_date, fields.device_type]
        .some((value) => value && value !== '');

    if (!hasData) {
        return 'inconclusive';
    }

    const validity = (fields.device_validity ?? '').toLowerCase();
    if (validity.includes('invalid') || validity.includes('not valid')) {
        return 'not_found';
    }

    return 'found';
}