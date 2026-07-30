const HOST_ID = 'incoming-call-card-host';

const formatPhone = (value) => {
    if (!value) {
        return 'Unknown number';
    }

    return value;
};

/**
 * Observe-only browser stage (S7): time from call.received_at to card show.
 * Never throws into the popup path.
 */
export const logIncomingCallPopupLatency = (call) => {
    try {
        if (!call?.call_id || !call?.received_at) {
            return;
        }

        const receivedAtMs = Date.parse(call.received_at);

        if (Number.isNaN(receivedAtMs)) {
            return;
        }

        const totalMs = Math.max(0, Math.round(Date.now() - receivedAtMs));

        // eslint-disable-next-line no-console
        console.info('[BonVoice Incoming Latency]', {
            stage: 'S7_browser_popup',
            duration_ms: totalMs,
            total_ms: totalMs,
            call_id: call.call_id,
            incident_id: call.incident_id ?? null,
            received_at: call.received_at,
        });
    } catch {
        // Observe-only.
    }
};

const normalizeIncidentId = (incidentId) => {
    if (incidentId === null || incidentId === undefined || incidentId === '') {
        return '';
    }

    return String(incidentId);
};

const normalizeActionUrl = (actionUrl) => (
    typeof actionUrl === 'string' ? actionUrl : ''
);

const buildCard = (call) => {
    const card = document.createElement('div');
    card.className = 'incoming-call-card card border-0 shadow';
    card.dataset.callId = call.call_id;
    card.dataset.incidentId = normalizeIncidentId(call.incident_id);
    card.dataset.actionUrl = normalizeActionUrl(call.action_url);
    card.setAttribute('role', 'status');
    card.setAttribute('aria-live', 'polite');

    const customerName = call.customer_name?.trim() || 'Unknown caller';
    const operator = call.assigned_operator?.trim() || 'Unassigned';
    const status = call.call_status ?? 'ringing';

    card.innerHTML = `
        <div class="card-body d-flex align-items-start gap-3 py-3">
            <div class="incoming-call-card__icon text-success">
                <i class="bi bi-telephone-inbound-fill fs-4" aria-hidden="true"></i>
            </div>
            <div class="flex-grow-1 min-w-0">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div>
                        <div class="fw-semibold">${customerName}</div>
                        <div class="text-muted small">${formatPhone(call.mobile_number)}</div>
                    </div>
                    <span class="badge text-bg-success text-uppercase">${status}</span>
                </div>
                <div class="text-muted small mt-1">Operator: ${operator}</div>
            </div>
            <div class="d-flex flex-column gap-1">
                ${call.action_url ? `<a href="${call.action_url}" class="btn btn-sm btn-primary">Open</a>` : ''}
                <button type="button" class="btn btn-sm btn-outline-secondary" data-incoming-call-dismiss>Dismiss</button>
            </div>
        </div>
    `;

    card.querySelector('[data-incoming-call-dismiss]')?.addEventListener('click', () => {
        card.remove();
    });

    return card;
};

const host = () => document.getElementById(HOST_ID);

const cardForCallId = (callId) => {
    const container = host();

    if (!container || typeof callId !== 'string' || callId.trim() === '') {
        return null;
    }

    return container.querySelector(`[data-call-id="${callId}"]`);
};

export const showIncomingCallCard = (call) => {
    const container = host();

    if (!container || !call?.call_id) {
        return;
    }

    const existing = cardForCallId(call.call_id);

    if (existing) {
        existing.replaceWith(buildCard(call));
        logIncomingCallPopupLatency(call);

        return;
    }

    container.prepend(buildCard(call));
    logIncomingCallPopupLatency(call);
};

/**
 * Remove the ringing popup for a call. Idempotent.
 *
 * @param {string | null | undefined} callId
 * @returns {boolean} true when a card was removed
 */
export const dismissIncomingCallCard = (callId) => {
    const existing = cardForCallId(typeof callId === 'string' ? callId : '');

    if (!existing) {
        return false;
    }

    existing.remove();

    return true;
};

/**
 * Update an existing card. Rebuilds when incident_id or action_url change so
 * Open stays current after Conversation Workspace bootstrap.
 */
export const updateIncomingCallCard = (call) => {
    const container = host();

    if (!container || !call?.call_id) {
        return;
    }

    const existing = cardForCallId(call.call_id);

    if (!existing) {
        showIncomingCallCard(call);

        return;
    }

    const nextIncidentId = normalizeIncidentId(call.incident_id);
    const nextActionUrl = normalizeActionUrl(call.action_url);
    const previousIncidentId = existing.dataset.incidentId ?? '';
    const previousActionUrl = existing.dataset.actionUrl ?? '';

    if (previousIncidentId !== nextIncidentId || previousActionUrl !== nextActionUrl) {
        existing.replaceWith(buildCard(call));

        return;
    }

    const statusBadge = existing.querySelector('.badge');

    if (statusBadge && call.call_status) {
        statusBadge.textContent = call.call_status;
    }
};

export const initIncomingCallCardHost = () => host();
