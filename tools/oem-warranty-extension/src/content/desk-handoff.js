(function () {
    const { DESK_WINDOW_MSG, postToDeskPage } = (() => {
        // Inline bridge constants (content script is not bundled; keep self-contained).
        const DESK_WINDOW_MSG = {
            HANDOFF_REQUEST: 'RADESK_OEM_HANDOFF_REQUEST',
            SESSION_PENDING: 'RADESK_OEM_HANDOFF_SESSION_PENDING',
            SESSION_READY: 'RADESK_OEM_HANDOFF_SESSION_READY',
            HANDOFF_STATUS: 'RADESK_OEM_HANDOFF_STATUS',
        };

        const postToDeskPage = (payload, origin) => {
            window.postMessage(payload, origin);
        };

        return { DESK_WINDOW_MSG, postToDeskPage };
    })();

    const HANDOFF_SELECTOR = '[data-oem-warranty-check]';
    const HANDOFF_TIMEOUT_MS = 12_000;
    const MSG_HANDOFF_REQUEST = 'OEM_HANDOFF_REQUEST';

    function publishHandoffStatus(detail, incidentId = null) {
        document.dispatchEvent(new CustomEvent('radium:oem-handoff-status', { detail }));
        postToDeskPage(
            {
                type: DESK_WINDOW_MSG.HANDOFF_STATUS,
                status: detail?.status,
                code: detail?.code,
                incident_id: incidentId != null ? Number(incidentId) : null,
            },
            window.location.origin,
        );
    }

    function publishHandoffRequest(trigger) {
        const incidentId = trigger.getAttribute('data-incident-id');
        const oem = trigger.getAttribute('data-oem-vendor');
        const serial = readSerialFromRow(trigger);

        document.dispatchEvent(new CustomEvent('radium:oem-handoff-request', {
            detail: { trigger },
        }));

        postToDeskPage(
            {
                type: DESK_WINDOW_MSG.HANDOFF_REQUEST,
                incident_id: incidentId ? Number(incidentId) : null,
                oem,
                serial,
            },
            window.location.origin,
        );
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
            const incidentId = trigger.getAttribute('data-incident-id');
            publishHandoffStatus(detail, incidentId);
        };

        const timeoutId = window.setTimeout(() => settle({ status: 'error', code: 'timeout' }), HANDOFF_TIMEOUT_MS);

        chrome.runtime.sendMessage({ type: MSG_HANDOFF_REQUEST, oem, serial }, (response) => {
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

    function sendSessionHandoffToExtension(payload, settle) {
        chrome.runtime.sendMessage(
            {
                type: MSG_HANDOFF_REQUEST,
                oem: payload.oem,
                serial: payload.serial,
                handoff_token: payload.handoff_token,
                session_public_id: payload.session_public_id,
                incident_id: payload.incident_id,
                mantra_capture_enabled: payload.mantra_capture_enabled === true,
                morpho_capture_enabled: payload.morpho_capture_enabled === true,
                result_capture_enabled: payload.result_capture_enabled === true,
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
    }

    /** @type {WeakMap<HTMLButtonElement, () => void>} */
    const sessionSettleByTrigger = new WeakMap();

    /** @type {WeakMap<HTMLButtonElement, number>} */
    const sessionTimeoutByTrigger = new WeakMap();

    function clearSessionHandoffTimeout(trigger) {
        const timeoutId = sessionTimeoutByTrigger.get(trigger);
        if (timeoutId != null) {
            window.clearTimeout(timeoutId);
            sessionTimeoutByTrigger.delete(trigger);
        }
    }

    function armSessionHandoffTimeout(trigger, onTimeout) {
        clearSessionHandoffTimeout(trigger);
        const timeoutId = window.setTimeout(onTimeout, HANDOFF_TIMEOUT_MS);
        sessionTimeoutByTrigger.set(trigger, timeoutId);
    }

    function findBusySessionTrigger(incidentId) {
        if (incidentId != null) {
            const scoped = document.querySelector(
                `[data-oem-warranty-check][data-incident-id="${incidentId}"][data-oem-handoff-busy="true"]`,
            );
            if (scoped instanceof HTMLButtonElement) {
                return scoped;
            }
        }

        const fallback = document.querySelector('[data-oem-handoff-busy="true"][data-oem-warranty-check]');
        return fallback instanceof HTMLButtonElement ? fallback : null;
    }

    window.addEventListener('message', (event) => {
        if (event.source !== window || event.origin !== window.location.origin) {
            return;
        }

        const data = event.data;
        if (!data?.type) {
            return;
        }

        if (data.type === DESK_WINDOW_MSG.HANDOFF_STATUS && data.status === 'error') {
            const trigger = findBusySessionTrigger(data.incident_id);
            if (!trigger) {
                return;
            }
            const settle = sessionSettleByTrigger.get(trigger);
            if (typeof settle === 'function') {
                settle({ status: 'error', code: data.code ?? 'handoff-failed' });
            }
            return;
        }

        if (data.type === DESK_WINDOW_MSG.SESSION_PENDING) {
            const trigger = findBusySessionTrigger(data.incident_id);
            const settle = trigger ? sessionSettleByTrigger.get(trigger) : null;
            if (trigger && typeof settle === 'function') {
                armSessionHandoffTimeout(trigger, () => {
                    settle({ status: 'error', code: 'timeout-awaiting-session' });
                });
            }
            return;
        }

        if (data.type !== DESK_WINDOW_MSG.SESSION_READY) {
            return;
        }

        const trigger = findBusySessionTrigger(data.incident_id);
        if (!trigger) {
            postToDeskPage(
                {
                    type: DESK_WINDOW_MSG.HANDOFF_STATUS,
                    status: 'error',
                    code: 'handoff-failed',
                    incident_id: data.incident_id ?? null,
                },
                window.location.origin,
            );
            return;
        }

        const settle = sessionSettleByTrigger.get(trigger);
        if (typeof settle !== 'function') {
            return;
        }

        if (!data.oem || !data.serial) {
            settle({ status: 'error', code: 'missing-serial-or-oem' });
            return;
        }

        armSessionHandoffTimeout(trigger, () => {
            settle({ status: 'error', code: 'timeout-awaiting-extension' });
        });
        sendSessionHandoffToExtension(data, settle);
    });

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
                publishHandoffStatus({ status: 'error', code: 'missing-serial-or-oem' }, incidentId);
                return;
            }

            if (sessionEnabled && incidentId && oem === 'mantra') {
                setBusy(trigger, true);
                let settled = false;
                const settle = (detail) => {
                    if (settled) {
                        return;
                    }
                    settled = true;
                    clearSessionHandoffTimeout(trigger);
                    sessionSettleByTrigger.delete(trigger);
                    setBusy(trigger, false);
                    const incidentIdAttr = trigger.getAttribute('data-incident-id');
                    publishHandoffStatus(detail, incidentIdAttr);
                };

                sessionSettleByTrigger.set(trigger, settle);
                armSessionHandoffTimeout(trigger, () => {
                    if (!settled) {
                        settle({ status: 'error', code: 'timeout-awaiting-session' });
                    }
                });
                publishHandoffRequest(trigger);

                return;
            }

            legacyHandoff(trigger, oem, serial);
        },
        true,
    );
})();