import {
    formatRdExpiryYmdForServiceReferenceInput,
    proposeRdSubscriptionExpiryFromMantraDraft,
} from './dashboard-oem-rd-expiry-prefill';

/** @type {Map<number, { sessionPublicId: string, completeAssignmentUrl: string, proposedRdExpiryYmd: string|null, proposedRdExpiryDisplay: string|null }>} */
const OEM_INLINE_COMPLETION = new Map();

export const getOemInlineCompletionContext = (incidentId) => {
    const id = Number(incidentId);
  if (!Number.isFinite(id) || id <= 0) {
        return null;
    }

    return OEM_INLINE_COMPLETION.get(id) ?? null;
};

export const clearOemInlineCompletionContext = (incidentId) => {
    OEM_INLINE_COMPLETION.delete(Number(incidentId));
};

export const registerOemInlineCompletionFromSession = (incidentId, session, baseUrl) => {
    const id = Number(incidentId);
    const draft = session?.draft;
    if (!draft || session?.status !== 'draft_ready') {
        clearOemInlineCompletionContext(id);
        return false;
    }

    const sessionPublicId = session.public_id;
    if (!sessionPublicId || !baseUrl) {
        return false;
    }

    const proposedRdExpiryYmd = proposeRdSubscriptionExpiryFromMantraDraft({
        ...draft,
        oem: draft.oem ?? session.oem ?? 'mantra',
    });

    OEM_INLINE_COMPLETION.set(id, {
        sessionPublicId,
        completeAssignmentUrl: `${baseUrl}/${id}/oem-handoff/sessions/${sessionPublicId}/complete-assignment`,
        proposedRdExpiryYmd,
        proposedRdExpiryDisplay: formatRdExpiryYmdForServiceReferenceInput(proposedRdExpiryYmd),
    });

    return true;
};