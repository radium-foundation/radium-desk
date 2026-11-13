/**
 * Read the serial from the dashboard row DOM (never from URLs or storage).
 *
 * @param {Element | null | undefined} trigger
 * @returns {string}
 */
export function readSerialFromDashboardCell(trigger) {
    const cell = trigger?.closest('.case-serial-cell');
    const valueHost = cell?.querySelector('.dashboard-u-serial-value');

    if (!valueHost) {
        return '';
    }

    const raw = valueHost.textContent ?? '';

    return raw.trim();
}
