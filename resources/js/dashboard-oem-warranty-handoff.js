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

const removeResultsPanel = (incidentId) => {
    const existing = document.querySelector(`[data-oem-results-panel="${incidentId}"]`);
    existing?.remove();
};

const formatOemLabel = (oem) => (oem === 'morpho' ? 'Morpho' : 'Mantra');

const renderMantraDetails = (draft) => `
    <dl class="mb-1 oem-handoff-draft-panel__dl">
        <dt>Model</dt><dd>${draft.model ?? '—'}</dd>
        <dt>Serial</dt><dd>${draft.serial_number ?? '—'}</dd>
        <dt>OEM warranty expiry</dt><dd>${draft.warranty_expiry_date ?? '—'}</dd>
        <dt>RD subscription expiry</dt><dd>${draft.rd_expiry_date ?? '—'}</dd>
        <dt>Captured</dt><dd>${draft.captured_at ?? '—'}</dd>
    </dl>`;

const renderMorphoDetails = (draft) => {
    const f = draft.fields ?? {};
    return `
    <dl class="mb-1 oem-handoff-draft-panel__dl">
        <dt>Serial</dt><dd>${f.serial_number ?? draft.serial_number ?? '—'}</dd>
        <dt>Device validity</dt><dd>${f.device_validity ?? '—'}</dd>
        <dt>Whitelist status</dt><dd>${f.whitelist_status ?? '—'}</dd>
        <dt>Whitelist date</dt><dd>${f.whitelist_date ?? '—'}</dd>
        <dt>Expiry date</dt><dd>${f.expiry_date ?? '—'}</dd>
        <dt>Validity (years)</dt><dd>${f.validity_years ?? '—'}</dd>
        <dt>Device type</dt><dd>${f.device_type ?? '—'}</dd>
        <dt>Captured</dt><dd>${draft.captured_at ?? '—'}</dd>
    </dl>`;
};

const attachPanelActions = ({
    baseUrl,
    incidentId,
    sessionPublicId,
    panel,
    showToast,
    sessionStatus,
}) => {
    panel.querySelector('[data-oem-result-confirm]')?.addEventListener('click', async (event) => {
        event.stopPropagation();
        try {
            const confirmUrl = `${baseUrl}/${incidentId}/oem-handoff/sessions/${sessionPublicId}/confirm`;
            await postJson(confirmUrl);
            removeResultsPanel(incidentId);
            clearPendingOemDraftReview(incidentId);
            showToast?.('OEM result confirmed and stored as informational evidence only.', { variant: 'success' });
            ACTIVE_DRAFTS.delete(incidentId);
        } catch (error) {
            showToast?.(error.message, { variant: 'danger' });
        }
    });

    panel.querySelector('[data-oem-result-reject]')?.addEventListener('click', async (event) => {
        event.stopPropagation();
        try {
            const rejectUrl = `${baseUrl}/${incidentId}/oem-handoff/sessions/${sessionPublicId}/reject`;
            await postJson(rejectUrl, { reason: 'Administrator rejected OEM capture' });
            removeResultsPanel(incidentId);
            clearPendingOemDraftReview(incidentId);
            showToast?.('OEM result rejected.', { variant: 'warning' });
            ACTIVE_DRAFTS.delete(incidentId);
        } catch (error) {
            showToast?.(error.message, { variant: 'danger' });
        }
    });

    if (sessionStatus === 'confirmed') {
        panel.querySelectorAll('[data-oem-result-confirm], [data-oem-result-reject]').forEach((el) => {
            el.remove();
        });
    }
};

const renderResultsPanel = (incidentId, session, { baseUrl, showToast }) => {
    removeResultsPanel(incidentId);
    const draft = session?.draft;
    if (!draft) {
        return;
    }

  const actionable = session?.status === 'draft_ready';
    if (!actionable && session?.status !== 'confirmed') {
        return;
    }

    const row = document.getElementById(`service-case-row-${incidentId}`);
    const hostCell = row?.querySelector('.case-serial-cell');
    if (!hostCell) {
        return;
    }

    const sessionPublicId = session.public_id;
    const oem = draft.oem ?? session.oem ?? 'mantra';
    ACTIVE_DRAFTS.set(incidentId, {
        session_public_id: sessionPublicId,
        incident_id: incidentId,
        oem,
    });

    const statusLabel = session.status_label ?? session.status ?? 'unknown';
    const outcome = draft.outcome ?? 'inconclusive';
    const details = oem === 'morpho' ? renderMorphoDetails(draft) : renderMantraDetails(draft);

    const panel = document.createElement('div');
    panel.className = 'oem-handoff-results-panel small border rounded bg-white p-2 mt-1';
    panel.setAttribute('data-oem-results-panel', String(incidentId));
    panel.setAttribute('data-oem-session-public-id', sessionPublicId);
    panel.setAttribute('role', 'status');

    panel.innerHTML = `
        <div class="fw-semibold">${formatOemLabel(oem)} OEM results</div>
        <div class="text-muted mb-1">Status: ${statusLabel} · Outcome: ${outcome} · Source: extension DOM</div>
        ${details}
        <p class="text-muted mb-2">${draft.disclaimer ?? 'OEM-reported values are informational only — not verified warranty or renewal entitlement.'}</p>
        ${actionable ? `
        <div class="d-flex gap-2 flex-wrap">
            <button type="button" class="btn btn-sm btn-outline-success" data-oem-result-confirm>
                Confirm result
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-oem-result-reject>
                Reject result
            </button>
        </div>` : '<p class="text-success mb-0">Result confirmed for audit.</p>'}
    `;

    if (actionable) {
        markPendingOemDraftReview(incidentId);
    }
    attachPanelActions({
        baseUrl,
        incidentId,
        sessionPublicId,
        panel,
        showToast,
        sessionStatus: session.status,
    });
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
        renderResultsPanel(incidentId, result.session, { baseUrl, showToast });
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
        visibleOemIncidentIds().forEach((incidentId) => {
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

        if (oem !== 'mantra' && oem !== 'morpho') {
            publishStatus({ status: 'error', code: 'handoff-failed' });
            return;
        }

        try {
            const sessionUrl = `${baseUrl}/${incidentId}/oem-handoff/sessions`;
            const started = await postJson(sessionUrl);
            const context = {
                handoff_token: started.handoff_token,
                session_public_id: started.session_public_id,
                incident_id: Number(incidentId),
                oem,
                mantra_capture_enabled: started.mantra_capture_enabled === true,
                morpho_capture_enabled: started.morpho_capture_enabled === true,
                result_capture_enabled: started.result_capture_enabled === true,
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
                morpho_capture_enabled: context.morpho_capture_enabled,
                result_capture_enabled: context.result_capture_enabled,
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

            renderResultsPanel(incidentId, result.session, { baseUrl, showToast });
            showToast?.('OEM result captured — awaiting your review in Desk.', { variant: 'info' });
        } catch (error) {
            showToast?.(error.message, { variant: 'danger' });
        }
    });
};