import { getWorkspaceSession } from './workspace/session';

const INCOMING_CALL_CHANNEL = 'radium-incoming-call';
const PROCESSED_STORAGE_PREFIX = 'radium.incoming-call.processed.';

/** @type {Set<string>} */
const processedCallIds = new Set();

/** @type {BroadcastChannel | null} */
let broadcastChannel = null;

try {
    if (typeof BroadcastChannel !== 'undefined') {
        broadcastChannel = new BroadcastChannel(INCOMING_CALL_CHANNEL);
        broadcastChannel.addEventListener('message', (event) => {
            const callId = event.data?.callId;

            if (event.data?.type === 'processed' && typeof callId === 'string' && callId !== '') {
                rememberProcessedCallId(callId, { announce: false });
            }
        });
    }
} catch {
    broadcastChannel = null;
}

const storageKeyForCall = (callId) => `${PROCESSED_STORAGE_PREFIX}${callId}`;

const logIncomingCallInteraction = (message, context = {}) => {
    if (!import.meta.env?.DEV) {
        return;
    }

    console.debug(`[Incoming Call] ${message}`, context);
};

const readProcessedFromStorage = (callId) => {
    try {
        return sessionStorage.getItem(storageKeyForCall(callId)) === '1'
            || localStorage.getItem(storageKeyForCall(callId)) === '1';
    } catch {
        return false;
    }
};

const rememberProcessedCallId = (callId, { announce = true } = {}) => {
    processedCallIds.add(callId);

    try {
        sessionStorage.setItem(storageKeyForCall(callId), '1');
        localStorage.setItem(storageKeyForCall(callId), '1');
    } catch {
        // Ignore storage failures in private browsing or quota errors.
    }

    if (announce) {
        broadcastChannel?.postMessage({ type: 'processed', callId });
    }
};

const hasProcessedCallId = (callId) => processedCallIds.has(callId) || readProcessedFromStorage(callId);

const claimCallId = (callId) => {
    if (typeof callId !== 'string' || callId.trim() === '') {
        return false;
    }

    if (hasProcessedCallId(callId)) {
        logIncomingCallInteraction('Skipped because call already processed', { call_id: callId });

        return false;
    }

    rememberProcessedCallId(callId);

    return true;
};

const parseIncidentId = (incidentId) => {
    const parsedIncidentId = Number(incidentId);

    if (!Number.isFinite(parsedIncidentId) || parsedIncidentId <= 0) {
        return null;
    }

    return parsedIncidentId;
};

/**
 * @param {Record<string, unknown> | null | undefined} interaction
 */
export const maybeHandleIncomingCallInteraction = (interaction) => {
    if (!interaction || typeof interaction !== 'object') {
        return;
    }

    if (interaction.channel !== 'phone') {
        return;
    }

    if (interaction.direction !== 'inbound') {
        return;
    }

    if (interaction.status !== 'answered') {
        return;
    }

    const incidentId = parseIncidentId(interaction.incident_id);

    if (incidentId === null) {
        logIncomingCallInteraction('Skipped because no incident', { interaction });

        return;
    }

    if (getWorkspaceSession().isActive()) {
        logIncomingCallInteraction('Skipped because workspace busy', {
            reasons: getWorkspaceSession().getActiveReasons(),
            interaction,
        });

        return;
    }

    const callId = interaction.call_id;

    if (!claimCallId(typeof callId === 'string' ? callId : '')) {
        return;
    }

    logIncomingCallInteraction('Auto-opening Customer360', { interaction });

    document.dispatchEvent(new CustomEvent('customer360:open', {
        detail: {
            incidentId,
            referenceLabel: typeof interaction.reference_label === 'string'
                ? interaction.reference_label
                : '',
        },
    }));
};

export const resetIncomingCallInteractionState = () => {
    processedCallIds.clear();

    try {
        Object.keys(sessionStorage).forEach((key) => {
            if (key.startsWith(PROCESSED_STORAGE_PREFIX)) {
                sessionStorage.removeItem(key);
            }
        });
        Object.keys(localStorage).forEach((key) => {
            if (key.startsWith(PROCESSED_STORAGE_PREFIX)) {
                localStorage.removeItem(key);
            }
        });
    } catch {
        // Ignore storage cleanup failures in tests or restricted contexts.
    }
};
