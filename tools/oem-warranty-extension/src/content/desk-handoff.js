(function () {
    const HANDOFF_SELECTOR = '[data-oem-warranty-check]';
    const HANDOFF_TIMEOUT_MS = 12_000;

    /** @param {Record<string, unknown>} detail */
    function publishHandoffStatus(detail) {
        document.dispatchEvent(new CustomEvent('radium:oem-handoff-status', { detail }));
    }

    /** @param {HTMLButtonElement} trigger */
    function setBusy(trigger, busy) {
        if (busy) {
            trigger.setAttribute('data-oem-handoff-busy', 'true');
            trigger.setAttribute('aria-busy', 'true');
            trigger.setAttribute('disabled', '');

            return;
        }

        trigger.removeAttribute('data-oem-handoff-busy');
        trigger.removeAttribute('aria-busy');
        trigger.removeAttribute('disabled');
    }

    /** @param {Element} trigger */
    function readSerialFromRow(trigger) {
        const cell = trigger.closest('.case-serial-cell');
        const valueHost = cell?.querySelector('.dashboard-u-serial-value');

        return valueHost?.textContent?.trim() ?? '';
    }

    document.addEventListener(
        'click',
        (event) => {
            const target = event.target;
            if (!(target instanceof Element)) {
                return;
            }

            const trigger = target.closest(HANDOFF_SELECTOR);
            if (!trigger || !(trigger instanceof HTMLButtonElement)) {
                return;
            }

            if (trigger.hasAttribute('data-oem-handoff-busy')) {
                event.preventDefault();
                event.stopPropagation();

                return;
            }

            event.preventDefault();
            event.stopPropagation();

            const oem = trigger.getAttribute('data-oem-vendor');
            const serial = readSerialFromRow(trigger);
            if (!oem || !serial) {
                publishHandoffStatus({ status: 'error', code: 'missing-serial-or-oem' });

                return;
            }

            setBusy(trigger, true);

            let settled = false;
            /** @param {Record<string, unknown>} detail */
            const settle = (detail) => {
                if (settled) {
                    return;
                }
                settled = true;
                clearTimeout(timeoutId);
                setBusy(trigger, false);
                publishHandoffStatus(detail);
            };

            const timeoutId = window.setTimeout(() => {
                settle({ status: 'error', code: 'timeout' });
            }, HANDOFF_TIMEOUT_MS);

            chrome.runtime.sendMessage(
                {
                    type: 'OEM_HANDOFF_REQUEST',
                    oem,
                    serial,
                },
                (response) => {
                    if (chrome.runtime.lastError) {
                        settle({ status: 'error', code: 'extension-unavailable' });

                        return;
                    }

                    if (!response?.ok) {
                        settle({ status: 'error', code: response?.error ?? 'handoff-failed' });

                        return;
                    }

                    settle({ status: 'ok', code: 'handoff-started' });
                },
            );
        },
        true,
    );
})();
