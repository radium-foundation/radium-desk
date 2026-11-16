/**
 * Parse visible Morpho validation DOM after administrator-triggered OEM checks only.
 * Does not invoke Selectize, blur, or form submission.
 *
 * @param {Document} doc
 */
export function readMorphoDisplayedSerial(doc) {
    const label = doc.querySelector('#lblSerial, [data-oem-serial]');
    if (!label) {
        return '';
    }

    if (label instanceof HTMLInputElement) {
        return label.value.trim();
    }

    return label.textContent?.trim() ?? '';
}

/**
 * @param {Document} doc
 * @returns {{ serial_number: string, device_validity: string, whitelist_status: string, whitelist_date: string, expiry_date: string, validity_years: string, device_type: string, panel_visible: boolean }}
 */
export function readMorphoResultFields(doc) {
    const panel = doc.querySelector('#morpho-validation-panel, #divValidationResult, .validation-result-panel');

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
        serial_number: readMorphoDisplayedSerial(doc),
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
 * Fields safe to send to Desk (no internal parse metadata).
 *
 * @param {ReturnType<typeof readMorphoResultFields>} parsed
 */
export function morphoFieldsForCapture(parsed) {
    return {
        serial_number: parsed.serial_number,
        device_validity: parsed.device_validity,
        whitelist_status: parsed.whitelist_status,
        whitelist_date: parsed.whitelist_date,
        expiry_date: parsed.expiry_date,
        validity_years: parsed.validity_years,
        device_type: parsed.device_type,
    };
}

/**
 * @param {Document} doc
 * @returns {boolean}
 */
export function isMorphoResultVisible(doc) {
    const fields = readMorphoResultFields(doc);
    const hasResultSignals = [fields.device_validity, fields.whitelist_status, fields.expiry_date, fields.device_type]
        .some((value) => value && value !== '');

    if (!hasResultSignals) {
        return false;
    }

    if (fields.panel_visible) {
        return true;
    }

    const tbody = doc.querySelector('#tblDeviceResult tbody, table.device-result tbody');
    return Boolean(tbody && tbody.querySelectorAll('tr').length > 0);
}

/**
 * @param {ReturnType<typeof readMorphoResultFields>} fields
 * @returns {'already_whitelisted' | 'valid_not_whitelisted' | 'invalid_device' | 'ambiguous' | 'inconclusive'}
 */
export function classifyMorphoWhitelistQualification(fields) {
    const deviceValidity = (fields.device_validity ?? '').trim();
    const whitelistStatus = (fields.whitelist_status ?? '').trim();
    const validityLower = deviceValidity.toLowerCase();
    const whitelistLower = whitelistStatus.toLowerCase();

    const isInvalidValidity = validityLower.includes('invalid')
        || validityLower.includes('not valid')
        || validityLower.includes('does not exist');

    if (isInvalidValidity) {
        return 'invalid_device';
    }

    const isNotWhitelisted = /\bnot\s+whitelisted\b/.test(whitelistLower)
        || (/\bvalid\b/.test(whitelistLower) && /\bnot\s+whitelisted\b/.test(whitelistLower));

    if (isNotWhitelisted) {
        return 'valid_not_whitelisted';
    }

    const isAlreadyWhitelisted = /\balready\s+whitelisted\b/.test(whitelistLower)
        || /\bdevice\s+already\s+whitelisted\b/.test(whitelistLower)
        || /^\s*whitelisted\s*$/.test(whitelistLower);

    if (isAlreadyWhitelisted) {
        return 'already_whitelisted';
    }

    if (deviceValidity !== '' && /\bvalid\b/.test(validityLower) && whitelistStatus === '') {
        return 'valid_not_whitelisted';
    }

    if (deviceValidity !== '' || whitelistStatus !== '') {
        return 'ambiguous';
    }

    return 'inconclusive';
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

    const qualification = classifyMorphoWhitelistQualification(fields);
    if (qualification === 'invalid_device') {
        return 'not_found';
    }

    if (qualification === 'inconclusive') {
        return 'inconclusive';
    }

    return 'found';
}