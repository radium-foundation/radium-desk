const HOST_ID = 'incoming-call-card-host';

const formatPhone = (value) => {
    if (!value) {
        return 'Unknown number';
    }

    return value;
};

const buildCard = (call) => {
    const card = document.createElement('div');
    card.className = 'incoming-call-card card border-0 shadow';
    card.dataset.callId = call.call_id;
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

export const showIncomingCallCard = (call) => {
    const container = host();

    if (!container || !call?.call_id) {
        return;
    }

    const existing = container.querySelector(`[data-call-id="${call.call_id}"]`);

    if (existing) {
        existing.replaceWith(buildCard(call));

        return;
    }

    container.prepend(buildCard(call));
};

export const updateIncomingCallCard = (call) => {
    const container = host();

    if (!container || !call?.call_id) {
        return;
    }

    const existing = container.querySelector(`[data-call-id="${call.call_id}"]`);

    if (!existing) {
        showIncomingCallCard(call);

        return;
    }

    const statusBadge = existing.querySelector('.badge');

    if (statusBadge && call.call_status) {
        statusBadge.textContent = call.call_status;
    }
};

export const initIncomingCallCardHost = () => host();
