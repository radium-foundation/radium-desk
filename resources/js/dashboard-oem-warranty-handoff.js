const HANDOFF_ERROR_MESSAGES = {
    'extension-unavailable':
        'OEM check extension is not installed or is disabled. Install the internal Radium OEM handoff extension.',
    timeout: 'OEM handoff timed out. Try again or enter the serial manually on the OEM site.',
    'origin-denied': 'This Desk origin is not permitted to start an OEM handoff.',
    'invalid-payload': 'Could not hand off an invalid serial or OEM selection.',
    'missing-serial-or-oem': 'Serial or device model is missing for this row.',
    'handoff-failed': 'OEM handoff could not be started.',
};

/**
 * @param {(message: string, options?: object) => void} showToast
 */
export const initDashboardOemWarrantyHandoff = (showToast) => {
    if (typeof showToast !== 'function') {
        return;
    }

    document.addEventListener('radium:oem-handoff-status', (event) => {
        const detail = event?.detail;
        if (!detail || typeof detail !== 'object') {
            return;
        }

        if (detail.status === 'ok') {
            return;
        }

        const code = detail.code ?? 'handoff-failed';
        const message = HANDOFF_ERROR_MESSAGES[code] ?? HANDOFF_ERROR_MESSAGES['handoff-failed'];
        showToast(message, { variant: 'danger' });
    });
};
