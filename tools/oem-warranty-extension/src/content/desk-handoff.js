(function () {
    const HANDOFF_SELECTOR = '[data-oem-warranty-check]';
    const HANDOFF_TIMEOUT_MS = 12_000;

    function publishHandoffStatus(detail) {
        document.dispatchEvent(new CustomEvent('radium:oem-handoff-status', { detail }));
    }

    function publishHandoffRequest(trigger) {
        document.dispatchEvent(new CustomEvent('radium:oem-handoff-request', {
            detail: { trigger },
        }));
    }

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

    function readSerialFromRow(trigger) {
        const cell = trigger.closest('.case-serial-cell');
        return cell?.querySelector('.dashboard-u-serial-value')?.textContent?.trim() ?? '';
    }

    function legacyHandoff(trigger, oem, serial) {
        setBusy(trigger, true);
        let settled = false;
        const settle = (detail) => {
            if (settled) {
                return;
            }
            settled = true;
            clearTimeout(timeoutId);
            setBusy(trigger, false);
            publishHandoffStatus(detail);
        };

        const timeoutId = window.setTimeout(() => settle({ status: 'error', code: 'timeout' }), HANDOFF_TIMEOUT_MS);

        chrome.runtime.sendMessage({ type: 'OEM_HANDOFF_REQUEST', oem, serial }, (response) => {
            if (chrome.runtime.lastError) {
                settle({ status: 'error', code: 'extension-unavailable' });
                return;
            }
            if (!response?.ok) {
                settle({ status: 'error', code: response?.error ?? 'handoff-failed' });
                return;
            }
            settle({ status: 'ok', code: 'handoff-started' });
        });
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
            const incidentId = trigger.getAttribute('data-incident-id');
            const sessionEnabled = document.getElementById('dashboard-page')?.dataset?.dashboardOemHandoffEnabled === '1';

            if (!oem || !serial) {
                publishHandoffStatus({ status: 'error', code: 'missing-serial-or-oem' });
                return;
            }

            if (sessionEnabled && incidentId && oem === 'mantra') {
                setBusy(trigger, true);
                publishHandoffRequest(trigger);

                const onStatus = (statusEvent) => {
                    const detail = statusEvent.detail;
                    if (!detail) {
                        return;
                    }
                    document.removeEventListener('radium:oem-handoff-status', onStatus);
                    setBusy(trigger, false);
                };
                document.addEventListener('radium:oem-handoff-status', onStatus);

                window.setTimeout(() => {
                    document.removeEventListener('radium:oem-handoff-status', onStatus);
                    if (trigger.hasAttribute('data-oem-handoff-busy')) {
                        setBusy(trigger, false);
                    }
                }, HANDOFF_TIMEOUT_MS + 5000);

                return;
            }

            legacyHandoff(trigger, oem, serial);
        },
        true,
    );
})();
