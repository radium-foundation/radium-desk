import { showIncomingCallCard, updateIncomingCallCard } from './incoming-call-card';

const isIncomingPhoneInteraction = (interaction) => (
    interaction
    && typeof interaction === 'object'
    && interaction.channel === 'phone'
    && interaction.direction === 'inbound'
    && typeof interaction.call_id === 'string'
    && interaction.call_id.trim() !== ''
);

/**
 * @param {Record<string, unknown> | null | undefined} interaction
 * @param {{ actionUrl?: string | null, assignedOperator?: string | null, receivedAt?: string | null }} [options]
 */
export const buildCallPayloadFromInteraction = (interaction, options = {}) => {
    if (!isIncomingPhoneInteraction(interaction)) {
        return null;
    }

    const incidentId = interaction.incident_id ?? null;
    const actionUrl = options.actionUrl
        ?? (incidentId ? `/dashboard?open_customer_360=${incidentId}` : '/dashboard');

    return {
        call_id: interaction.call_id,
        customer_name: interaction.customer_name ?? null,
        mobile_number: interaction.customer_phone ?? null,
        call_status: interaction.status ?? 'ringing',
        assigned_operator: options.assignedOperator ?? null,
        received_at: options.receivedAt ?? new Date().toISOString(),
        incident_id: incidentId,
        action_url: actionUrl,
    };
};

/**
 * @param {Record<string, unknown> | null | undefined} payload
 */
export const resolveIncomingCallPayload = (payload) => {
    if (payload?.call?.call_id) {
        return payload.call;
    }

    return buildCallPayloadFromInteraction(
        payload?.interaction,
        {
            actionUrl: payload?.url ?? payload?.action_url ?? null,
            receivedAt: payload?.created_at ?? null,
        },
    );
};

/**
 * Shared entry point for Reverb, NotificationCreated, and poll delivery paths.
 *
 * @param {Record<string, unknown> | null | undefined} payload
 * @param {{ dedupe?: (key: string) => boolean }} [options]
 */
export const renderIncomingCallNotification = (payload, options = {}) => {
    const call = resolveIncomingCallPayload(payload);

    if (!call?.call_id) {
        return false;
    }

    const dedupeKey = `incoming-call:${call.call_id}`;

    if (typeof options.dedupe === 'function' && options.dedupe(dedupeKey)) {
        updateIncomingCallCard(call);

        return true;
    }

    showIncomingCallCard(call);

    return true;
};

/**
 * @param {Record<string, unknown> | null | undefined} payload
 */
export const maybeShowIncomingCallCardFromNotification = (payload) => renderIncomingCallNotification(payload);
