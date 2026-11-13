import { csrfToken } from './workspace/http';

const HANDOFF_ERROR_MESSAGES = {
    'extension-unavailable':
        'OEM check extension is not installed or is disabled. Install the internal Radium OEM handoff extension.',
    timeout: 'OEM handoff timed out. Try again or enter the serial manually on the OEM site.',
    'origin-denied': 'This Desk origin is not permitted to start an OEM handoff.',
    'invalid-payload': 'Could not hand off an invalid serial or OEM selection.',
    'missing-serial-or-oem': 'Serial or device model is missing for this row.',
    'handoff-failed': 'OEM handoff could not be started.',
    'session-disabled': 'Dashboard OEM handoff sessions are disabled.',
};

/** @type {Map<number, { handoff_token?: string, session_public_id: string, incident_id: number }>} */
const ACTIVE_DRAFTS = new Map();

const PENDING_REVIEW_STORAGE_KEY = 'radium-oem-handoff-pending-review';

/** Shown when a draft was expected (e.g. after refresh) but recovery returned 404. */
export const OEM_DRAFT_NOT_RECOVERABLE_MESSAGE =
    'No saved Mantra OEM result is available for this row. Start a new OEM check from the OEM icon.';

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

const removeDraftPanel = (incidentId) => {
    const existing = document.querySelector(`[data-oem-draft-panel="${incidentId}"]`);
    existing?.remove();
};

const attachReviewHandler = ({
    baseUrl,
    incidentId,
    sessionPublicId,
    panel,
    showToast,
}) => {
    panel.querySelector('[data-oem-draft-review]')?.addEventListener('click', async (event) => {
        event.stopPropagation();
        try {
            const reviewUrl = `${baseUrl}/${incidentId}/oem-handoff/sessions/${sessionPublicId}/review`;
            await postJson(reviewUrl);
            removeDraftPanel(incidentId);
            clearPendingOemDraftReview(incidentId);
            showToast?.('Draft marked reviewed (not saved as warranty record).', { variant: 'success' });
            ACTIVE_DRAFTS.delete(incidentId);
        } catch (error) {
            showToast?.(error.message, { variant: 'danger' });
        }
    });
};

const renderDraftPanel = (incidentId, session, { baseUrl, showToast }) => {
    removeDraftPanel(incidentId);
    const draft = session?.draft;
    if (!draft || session?.status !== 'draft_ready') {
        return;
    }

    const row = document.getElementById(`service-case-row-${incidentId}`);
    const hostCell = row?.querySelector('.case-serial-cell');
    if (!hostCell) {
        return;
    }

    const sessionPublicId = session.public_id;
    ACTIVE_DRAFTS.set(incidentId, {
        session_public_id: sessionPublicId,
        incident_id: incidentId,
    });

    const panel = document.createElement('div');
    panel.className = 'oem-handoff-draft-panel small border rounded bg-white p-2 mt-1';
    panel.setAttribute('data-oem-draft-panel', String(incidentId));
    panel.setAttribute('data-oem-session-public-id', sessionPublicId);
    panel.setAttribute('role', 'status');

    const warranty = draft.warranty_expiry_date ?? '—';
    const rd = draft.rd_expiry_date ?? '—';
    const outcome = draft.outcome ?? 'inconclusive';

    panel.innerHTML = `
        <div class="fw-semibold">Mantra OEM draft (review only)</div>
        <div class="text-muted">Outcome: ${outcome} · Source: extension DOM</div>
        <dl class="mb-1 oem-handoff-draft-panel__dl">
            <dt>Model</dt><dd>${draft.model ?? '—'}</dd>
            <dt>Serial</dt><dd>${draft.serial_number ?? '—'}</dd>
            <dt>OEM warranty expiry</dt><dd>${warranty}</dd>
            <dt>RD subscription expiry</dt><dd>${rd}</dd>
            <dt>Captured</dt><dd>${draft.captured_at ?? '—'}</dd>
        </dl>
        <p class="text-muted mb-2">OEM-reported dates are not verified warranty confirmation.</p>
        <button type="button" class="btn btn-sm btn-outline-primary" data-oem-draft-review>
            Mark draft reviewed
        </button>
    `;

    markPendingOemDraftReview(incidentId);
    attachReviewHandler({ baseUrl, incidentId, sessionPublicId, panel, showToast });
    hostCell.appendChild(panel);
};

const sendExtensionHandoff = (payload) => new Promise((resolve) => {
    if (typeof chrome === 'undefined' || !chrome.runtime?.sendMessage) {
        resolve({ ok: false, error: 'extension-unavailable' });
        return;
    }

    chrome.runtime.sendMessage(payload, (response) => {
        if (chrome.runtime.lastError) {
            resolve({ ok: false, error: 'extension-unavailable' });
            return;
        }
        resolve(response ?? { ok: false, error: 'handoff-failed' });
    });
});

const visibleMantraIncidentIds = () => {
    const ids = new Set();
    document.querySelectorAll('[data-oem-warranty-check][data-incident-id][data-oem-vendor="mantra"]').forEach((el) => {
        const incidentId = el.getAttribute('data-incident-id');
        if (incidentId) {
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
        renderDraftPanel(incidentId, result.session, { baseUrl, showToast });
    }
};

export const initDashboardOemWarrantyHandoff = (showToast) => {
    const pageRoot = document.getElementById('dashboard-page');
    if (!pageRoot) {
        return;
    }

    const handoffEnabled = pageRoot.dataset.dashboardOemHandoffEnabled === '1';
    const baseUrl = pageRoot.dataset.dashboardOemHandoffUrl ?? '';

    const publishStatus = (detail) => {
        document.dispatchEvent(new CustomEvent('radium:oem-handoff-status', { detail }));
    };

    if (handoffEnabled && baseUrl) {
        visibleMantraIncidentIds().forEach((incidentId) => {
            void recoverDraftForIncident(incidentId, baseUrl, showToast);
        });
    }

    document.addEventListener('radium:oem-handoff-status', (event) => {
        const detail = event?.detail;
        if (!detail || detail.status === 'ok') {
            return;
        }
        const code = detail.code ?? 'handoff-failed';
        const message = HANDOFF_ERROR_MESSAGES[code] ?? HANDOFF_ERROR_MESSAGES['handoff-failed'];
        showToast?.(message, { variant: 'danger' });
    });

    document.addEventListener('radium:oem-handoff-request', async (event) => {
        const trigger = event.detail?.trigger;
        if (!(trigger instanceof HTMLButtonElement)) {
            return;
        }

        const incidentId = trigger.getAttribute('data-incident-id');
        const oem = trigger.getAttribute('data-oem-vendor');
        const serial = trigger.closest('.case-serial-cell')?.querySelector('.dashboard-u-serial-value')?.textContent?.trim();

        if (!incidentId || !oem || !serial) {
            publishStatus({ status: 'error', code: 'missing-serial-or-oem' });
            return;
        }

        if (!handoffEnabled) {
            await sendExtensionHandoff({ type: 'OEM_HANDOFF_REQUEST', oem, serial });
            publishStatus({ status: 'ok', code: 'handoff-started' });
            return;
        }

        if (oem !== 'mantra') {
            publishStatus({ status: 'error', code: 'handoff-failed' });
            showToast?.('Mantra-only result return is enabled in this pilot.', { variant: 'warning' });
            return;
        }

        try {
            const sessionUrl = `${baseUrl}/${incidentId}/oem-handoff/sessions`;
            const started = await postJson(sessionUrl);
            const context = {
                handoff_token: started.handoff_token,
                session_public_id: started.session_public_id,
                incident_id: Number(incidentId),
                mantra_capture_enabled: started.mantra_capture_enabled === true,
            };
            ACTIVE_DRAFTS.set(Number(incidentId), context);

            const response = await sendExtensionHandoff({
                type: 'OEM_HANDOFF_REQUEST',
                oem,
                serial,
                handoff_token: context.handoff_token,
                session_public_id: context.session_public_id,
                incident_id: context.incident_id,
                mantra_capture_enabled: context.mantra_capture_enabled,
            });

            if (!response?.ok) {
                publishStatus({ status: 'error', code: response?.error ?? 'handoff-failed' });
                return;
            }

            publishStatus({ status: 'ok', code: 'handoff-started' });
            void getJson(`${baseUrl}/${incidentId}/oem-handoff/sessions/${context.session_public_id}`);
        } catch (error) {
            publishStatus({ status: 'error', code: 'session-disabled' });
            showToast?.(error.message, { variant: 'danger' });
        }
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
            showToast?.('OEM draft rejected (session mismatch).', { variant: 'danger' });
            return;
        }

        try {
            const draftUrl = `${baseUrl}/${incidentId}/oem-handoff/sessions/${context.session_public_id}/draft`;
            const result = await postJson(draftUrl, {
                handoff_token: context.handoff_token,
                outcome: data.outcome,
                rows: data.rows ?? [],
            });

            renderDraftPanel(incidentId, result.session, { baseUrl, showToast });
            showToast?.('Mantra OEM draft ready for review.', { variant: 'info' });
        } catch (error) {
            showToast?.(error.message, { variant: 'danger' });
        }
    });
};
