import {
    clearOemInlineCompletionContext,
    registerOemInlineCompletionFromSession,
} from './dashboard-oem-inline-completion';
import {
    OEM_INLINE_COMPLETION_READY,
    initDashboardOemRefServicePrefill,
    scheduleOemServiceReferencePrefill,
} from './dashboard-oem-ref-service-prefill';
import { csrfToken } from './workspace/http';

export { clearOemInlineCompletionContext, getOemInlineCompletionContext } from './dashboard-oem-inline-completion';
export {
    formatRdExpiryYmdForServiceReferenceInput,
    normalizeRdSubscriptionExpiryYmd,
    proposeRdSubscriptionExpiryFromMantraDraft,
} from './dashboard-oem-rd-expiry-prefill';
export { initDashboardOemRefServicePrefill, tryPrefillOemServiceReferenceInput } from './dashboard-oem-ref-service-prefill';

/** window.postMessage types (must match extension content script). */
export const DESK_OEM_WINDOW_MSG = {
    HANDOFF_REQUEST: 'RADESK_OEM_HANDOFF_REQUEST',
    SESSION_PENDING: 'RADESK_OEM_HANDOFF_SESSION_PENDING',
    SESSION_READY: 'RADESK_OEM_HANDOFF_SESSION_READY',
    HANDOFF_STATUS: 'RADESK_OEM_HANDOFF_STATUS',
};

const HANDOFF_ERROR_MESSAGES = {
    'extension-unavailable':
        'OEM check extension is not installed or is disabled. Install the internal Radium OEM handoff extension.',
    timeout: 'OEM handoff timed out. Try again or enter the serial manually on the OEM site.',
    'timeout-awaiting-session':
        'Desk did not finish creating the OEM session in time. Hard-refresh the dashboard, install extension v0.3.9+, then retry.',
    'timeout-awaiting-extension':
        'The OEM session was created but the browser extension did not open Mantra in time. Install extension v0.3.9+ and retry.',
    'origin-denied': 'This Desk origin is not permitted to start an OEM handoff.',
    'invalid-payload': 'Could not hand off an invalid serial or OEM selection.',
    'missing-serial-or-oem': 'Serial or device model is missing for this row.',
    'handoff-failed': 'OEM handoff could not be started.',
    'session-disabled': 'Dashboard OEM handoff sessions are disabled.',
};

/** @type {Map<number, { handoff_token?: string, session_public_id: string, incident_id: number, oem?: string }>} */
const ACTIVE_DRAFTS = new Map();

const PENDING_REVIEW_STORAGE_KEY = 'radium-oem-handoff-pending-review';

export const OEM_DRAFT_NOT_RECOVERABLE_MESSAGE =
    'No saved OEM result is available for this row. Start a new OEM check from the OEM icon.';

const readPendingReviewIds = () => {
    try {
        const raw = sessionStorage.getItem(PENDING_REVIEW_STORAGE_KEY);
        const parsed = raw ? JSON.parse(raw) : [];
        return Array.isArray(parsed) ? parsed.map((id) => Number(id)).filter((id) => Number.isFinite(id)) : [];
    } catch {
        return [];
    }
};

const writePendingReviewIds = (ids) => {
    try {
        sessionStorage.setItem(PENDING_REVIEW_STORAGE_KEY, JSON.stringify([...new Set(ids)]));
    } catch {
        // Ignore quota or privacy mode errors.
    }
};

export const markPendingOemDraftReview = (incidentId) => {
    const ids = readPendingReviewIds();
    if (!ids.includes(incidentId)) {
        ids.push(incidentId);
        writePendingReviewIds(ids);
    }
};

export const clearPendingOemDraftReview = (incidentId) => {
    writePendingReviewIds(readPendingReviewIds().filter((id) => id !== incidentId));
};

export const hasPendingOemDraftReview = (incidentId) => readPendingReviewIds().includes(incidentId);

const postJson = async (url, body = {}) => {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });

    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
        const firstError = payload?.errors
            ? Object.values(payload.errors).flat()?.[0]
            : null;
        throw new Error(firstError ?? payload?.message ?? 'Request failed');
    }

    return payload;
};

const getJson = async (url) => {
    const response = await fetch(url, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });

    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
        throw new Error(payload?.message ?? 'Request failed');
    }

    return payload;
};

const removeLegacyOemResultsPanel = (incidentId) => {
    document.querySelector(`[data-oem-results-panel="${incidentId}"]`)?.remove();
};

const registerOemDraftForInlineRefCompletion = (incidentId, session, baseUrl, showToast) => {
    removeLegacyOemResultsPanel(incidentId);

    const draft = session?.draft;
    if (!draft) {
        return;
    }

    if (session?.status !== 'draft_ready') {
        clearOemInlineCompletionContext(incidentId);
        return;
    }

    const registered = registerOemInlineCompletionFromSession(incidentId, session, baseUrl);
    if (!registered) {
        return;
    }

    const oem = draft.oem ?? session.oem ?? 'mantra';
    ACTIVE_DRAFTS.set(Number(incidentId), {
        session_public_id: session.public_id,
        incident_id: Number(incidentId),
        oem,
    });
    markPendingOemDraftReview(incidentId);
    showToast?.('OEM RD expiry captured — eligible Service Reference will be filled automatically.', { variant: 'info' });
    document.dispatchEvent(new CustomEvent(OEM_INLINE_COMPLETION_READY, {
        detail: { incidentId: Number(incidentId) },
    }));
    scheduleOemServiceReferencePrefill(incidentId);
};

const visibleOemIncidentIds = () => {
    const ids = new Set();
    document.querySelectorAll('[data-oem-warranty-check][data-incident-id][data-oem-vendor]').forEach((el) => {
        const incidentId = el.getAttribute('data-incident-id');
        const vendor = el.getAttribute('data-oem-vendor');
        if (incidentId && (vendor === 'mantra' || vendor === 'morpho')) {
            ids.add(Number(incidentId));
        }
    });

    return [...ids];
};

const recoverDraftForIncident = async (incidentId, baseUrl, showToast) => {
    let response;
    try {
        response = await fetch(`${baseUrl}/${incidentId}/oem-handoff/recover`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });
    } catch {
        return;
    }

    if (response.status === 404) {
        if (hasPendingOemDraftReview(incidentId)) {
            clearPendingOemDraftReview(incidentId);
            showToast?.(OEM_DRAFT_NOT_RECOVERABLE_MESSAGE, { variant: 'warning' });
        }
        return;
    }

    if (!response.ok) {
        return;
    }

    const result = await response.json().catch(() => ({}));
    if (result?.session?.draft) {
        registerOemDraftForInlineRefCompletion(incidentId, result.session, baseUrl, showToast);
    }
};

export const initDashboardOemWarrantyHandoff = (showToast) => {
    const pageRoot = document.getElementById('dashboard-page');
    if (!pageRoot) {
        return;
    }

    initDashboardOemRefServicePrefill(showToast);

    const handoffEnabled = pageRoot.dataset.dashboardOemHandoffEnabled === '1';
    const baseUrl = pageRoot.dataset.dashboardOemHandoffUrl ?? '';

    const publishStatus = (detail, incidentId = null) => {
        document.dispatchEvent(new CustomEvent('radium:oem-handoff-status', { detail }));
        window.postMessage(
            {
                type: DESK_OEM_WINDOW_MSG.HANDOFF_STATUS,
                status: detail?.status,
                code: detail?.code,
                incident_id: incidentId != null ? Number(incidentId) : null,
            },
            window.location.origin,
        );
    };

    if (handoffEnabled && baseUrl) {
        visibleOemIncidentIds().forEach((incidentId) => {
            void recoverDraftForIncident(incidentId, baseUrl, showToast);
        });
    }

    const surfaceHandoffError = (detail) => {
        if (!detail || detail.status === 'ok') {
            return;
        }
        const code = detail.code ?? 'handoff-failed';
        const message = HANDOFF_ERROR_MESSAGES[code] ?? HANDOFF_ERROR_MESSAGES['handoff-failed'];
        showToast?.(message, { variant: 'danger' });
    };

    document.addEventListener('radium:oem-handoff-status', (event) => {
        surfaceHandoffError(event?.detail);
    });

    window.addEventListener('message', (event) => {
        if (event.source !== window || event.origin !== window.location.origin) {
            return;
        }
        const data = event.data;
        if (!data || data.type !== DESK_OEM_WINDOW_MSG.HANDOFF_STATUS) {
            return;
        }
        surfaceHandoffError({
            status: data.status,
            code: data.code,
        });
    });

    const startSessionBackedHandoff = async ({ incidentId, oem, serial }) => {
        if (!incidentId || !oem || !serial) {
            publishStatus({ status: 'error', code: 'missing-serial-or-oem' }, incidentId);
            return;
        }

        if (!handoffEnabled) {
            publishStatus({ status: 'error', code: 'session-disabled' }, incidentId);
            return;
        }

        if (oem !== 'mantra' && oem !== 'morpho') {
            publishStatus({ status: 'error', code: 'handoff-failed' }, incidentId);
            return;
        }

        try {
            window.postMessage(
                {
                    type: DESK_OEM_WINDOW_MSG.SESSION_PENDING,
                    incident_id: Number(incidentId),
                },
                window.location.origin,
            );

            const sessionUrl = `${baseUrl}/${incidentId}/oem-handoff/sessions`;
            const started = await postJson(sessionUrl);
            const context = {
                handoff_token: started.handoff_token,
                session_public_id: started.session_public_id,
                incident_id: Number(incidentId),
                oem,
                serial,
                mantra_capture_enabled: started.mantra_capture_enabled === true,
                morpho_capture_enabled: started.morpho_capture_enabled === true,
                result_capture_enabled: started.result_capture_enabled === true,
            };
            ACTIVE_DRAFTS.set(Number(incidentId), context);

            window.postMessage(
                {
                    type: DESK_OEM_WINDOW_MSG.SESSION_READY,
                    ...context,
                },
                window.location.origin,
            );

            void getJson(`${baseUrl}/${incidentId}/oem-handoff/sessions/${context.session_public_id}`);
        } catch (error) {
            publishStatus({ status: 'error', code: 'session-disabled' }, incidentId);
            showToast?.(error.message, { variant: 'danger' });
        }
    };

    document.addEventListener('radium:oem-handoff-request', async (event) => {
        const trigger = event.detail?.trigger;
        if (!(trigger instanceof HTMLButtonElement)) {
            return;
        }

        const incidentId = trigger.getAttribute('data-incident-id');
        const oem = trigger.getAttribute('data-oem-vendor');
        const serial = trigger.closest('.case-serial-cell')?.querySelector('.dashboard-u-serial-value')?.textContent?.trim();

        await startSessionBackedHandoff({ incidentId, oem, serial });
    });

    window.addEventListener('message', async (event) => {
        if (event.source !== window || event.origin !== window.location.origin) {
            return;
        }

        const data = event.data;
        if (!data || data.type !== DESK_OEM_WINDOW_MSG.HANDOFF_REQUEST) {
            return;
        }

        const incidentId = data.incident_id != null ? String(data.incident_id) : null;
        const oem = typeof data.oem === 'string' ? data.oem : null;
        const serial = typeof data.serial === 'string' ? data.serial : null;

        await startSessionBackedHandoff({ incidentId, oem, serial });
    });

    window.addEventListener('message', async (event) => {
        if (event.source !== window || event.origin !== window.location.origin) {
            return;
        }

        const data = event.data;
        if (!data || data.type !== 'RADESK_OEM_HANDOFF_DRAFT') {
            return;
        }

        const incidentId = Number(data.incident_id);
        const context = ACTIVE_DRAFTS.get(incidentId);
        if (!context || context.handoff_token !== data.handoff_token) {
            showToast?.('OEM result rejected (session mismatch).', { variant: 'danger' });
            return;
        }

        try {
            const draftUrl = `${baseUrl}/${incidentId}/oem-handoff/sessions/${context.session_public_id}/draft`;
            const result = await postJson(draftUrl, {
                handoff_token: context.handoff_token,
                outcome: data.outcome,
                rows: data.rows ?? [],
                fields: data.fields ?? null,
            });

            registerOemDraftForInlineRefCompletion(incidentId, result.session, baseUrl, showToast);
        } catch (error) {
            showToast?.(error.message, { variant: 'danger' });
        }
    });
};

export const clearOemHandoffDraftAfterAssignment = (incidentId) => {
    clearOemInlineCompletionContext(incidentId);
    clearPendingOemDraftReview(incidentId);
    ACTIVE_DRAFTS.delete(Number(incidentId));
};