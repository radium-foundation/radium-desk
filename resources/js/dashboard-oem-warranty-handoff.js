import { guardServiceReferenceAssignment } from './customer-intake';
import { csrfToken } from './workspace/http';

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

const attachCompletionForm = ({
    baseUrl,
    incidentId,
    sessionPublicId,
    panel,
    showToast,
    onAssignmentSaved,
    legacyVerificationModal,
    legacyContext,
}) => {
    const saveBtn = panel.querySelector('[data-oem-complete-save]');
    const refInput = panel.querySelector('[data-oem-service-reference]');
    const expiryInput = panel.querySelector('[data-oem-expiry-date]');
    const errorEl = panel.querySelector('[data-oem-complete-error]');

    const setError = (message) => {
        const invalid = Boolean(message);
        refInput?.classList.toggle('is-invalid', invalid);
        expiryInput?.classList.toggle('is-invalid', invalid);
        if (errorEl) {
            errorEl.textContent = message ?? '';
        }
    };

    saveBtn?.addEventListener('click', async (event) => {
        event.stopPropagation();
        if (panel.dataset.saving === '1') {
            return;
        }

        const serviceReference = refInput?.value?.trim() ?? '';
        const subscriptionExpiryDate = expiryInput?.value?.trim() ?? '';

        if (!serviceReference || !subscriptionExpiryDate) {
            setError('Service reference and expiry date are required.');
            return;
        }

        const performSave = async () => {
            if (panel.dataset.saving === '1') {
                return;
            }

            panel.dataset.saving = '1';
            setError('');
            const previousButtonHtml = saveBtn.innerHTML;
            saveBtn.disabled = true;
            saveBtn.setAttribute('aria-busy', 'true');
            saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';

            try {
                const url = `${baseUrl}/${incidentId}/oem-handoff/sessions/${sessionPublicId}/complete-assignment`;
                const data = await postJson(url, {
                    service_reference: serviceReference,
                    subscription_expiry_date: subscriptionExpiryDate,
                });
                removeResultsPanel(incidentId);
                clearPendingOemDraftReview(incidentId);
                ACTIVE_DRAFTS.delete(incidentId);
                showToast?.(data.message ?? 'Service reference and expiry saved.', { variant: 'success' });
                onAssignmentSaved?.(data);
            } catch (error) {
                setError(error.message);
                showToast?.(error.message, { variant: 'danger' });
            } finally {
                delete panel.dataset.saving;
                saveBtn.disabled = false;
                saveBtn.removeAttribute('aria-busy');
                saveBtn.innerHTML = previousButtonHtml;
            }
        };

        await guardServiceReferenceAssignment({
            requiresLegacyVerification: legacyContext?.requiresLegacyVerification === true,
            legacyVerificationUrl: legacyContext?.legacyVerificationUrl ?? null,
            legacyVerificationModal,
            legacyVerificationMode: legacyContext?.legacyVerificationMode ?? 'customer',
            onProceed: performSave,
        });
    });
};

const renderResultsPanel = (incidentId, session, { baseUrl, showToast, onAssignmentSaved, legacyVerificationModal }) => {
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
    const txnCell = row?.querySelector('.transaction-id-cell[data-inline-transaction="true"]');
    const legacyContext = txnCell ? {
        requiresLegacyVerification: txnCell.dataset.requiresLegacyVerification === 'true',
        legacyVerificationUrl: txnCell.dataset.legacyVerificationUrl ?? null,
        legacyVerificationMode: txnCell.dataset.legacyVerificationMode ?? 'customer',
    } : { requiresLegacyVerification: false };
    ACTIVE_DRAFTS.set(incidentId, {
        session_public_id: sessionPublicId,
        incident_id: incidentId,
        oem,
    });

    const statusLabel = session.status_label ?? session.status ?? 'unknown';
    const outcome = draft.outcome ?? 'inconclusive';
    const qualification = draft.whitelist_qualification ?? null;
    const details = oem === 'morpho' ? renderMorphoDetails(draft) : renderMantraDetails(draft);
    const panel = document.createElement('div');
    panel.className = 'oem-handoff-results-panel small border rounded bg-white p-2 mt-1';
    panel.setAttribute('data-oem-results-panel', String(incidentId));
    panel.setAttribute('data-oem-session-public-id', sessionPublicId);
    panel.setAttribute('role', 'status');

    panel.innerHTML = `
        <div class="fw-semibold">${formatOemLabel(oem)} OEM results</div>
        <div class="text-muted mb-1">Status: ${statusLabel} · Outcome: ${outcome}${qualification ? ` · Qualification: ${qualification}` : ''} · Source: extension DOM</div>
        ${details}
        <p class="text-muted mb-2">${draft.disclaimer ?? 'OEM-reported values are informational only — not verified warranty or renewal entitlement.'}</p>
        ${actionable ? `
        <div class="oem-handoff-completion-form mt-2">
            <p class="small fw-semibold mb-1">Administrator completion</p>
            <p class="text-muted small mb-2">Enter the official service reference and RD subscription expiry to close this case.</p>
            <div class="mb-2">
                <label class="form-label small mb-0">Expiry Date</label>
                <input type="date"
                       class="form-control form-control-sm"
                       data-oem-expiry-date
                       aria-label="RD subscription expiry date">
                <div class="form-text">RD subscription expiry — not OEM warranty expiry.</div>
            </div>
            <div class="input-group input-group-sm">
                <input type="text"
                       class="form-control"
                       data-oem-service-reference
                       placeholder="Service Reference"
                       maxlength="100"
                       aria-label="Service Reference Number">
                <button type="button"
                        class="btn btn-outline-success"
                        data-oem-complete-save
                        aria-label="Save service reference and close case">
                    <i class="bi bi-check-lg" aria-hidden="true"></i>
                </button>
            </div>
            <div class="invalid-feedback d-block small" data-oem-complete-error></div>
        </div>` : '<p class="text-success mb-0">OEM evidence stored. Case completion recorded.</p>'}
    `;

    if (actionable) {
        markPendingOemDraftReview(incidentId);
    }
    if (actionable) {
        attachCompletionForm({
            baseUrl,
            incidentId,
            sessionPublicId,
            panel,
            showToast,
            onAssignmentSaved,
            legacyVerificationModal,
            legacyContext,
        });
    }
    hostCell.appendChild(panel);
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

const recoverDraftForIncident = async (incidentId, baseUrl, showToast, onAssignmentSaved, legacyVerificationModal) => {
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
        renderResultsPanel(incidentId, result.session, {
            baseUrl,
            showToast,
            onAssignmentSaved,
            legacyVerificationModal,
        });
    }
};

export const initDashboardOemWarrantyHandoff = (showToast, { onAssignmentSaved, legacyVerificationModal } = {}) => {
    const pageRoot = document.getElementById('dashboard-page');
    if (!pageRoot) {
        return;
    }

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
            void recoverDraftForIncident(incidentId, baseUrl, showToast, onAssignmentSaved, legacyVerificationModal);
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

            renderResultsPanel(incidentId, result.session, {
                baseUrl,
                showToast,
                onAssignmentSaved,
                legacyVerificationModal,
            });
            showToast?.('OEM result captured — enter service reference and expiry to complete.', { variant: 'info' });
        } catch (error) {
            showToast?.(error.message, { variant: 'danger' });
        }
    });
};