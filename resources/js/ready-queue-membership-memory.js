/** @type {Map<number, 'in' | 'out'>} */
const readyMembershipMemory = new Map();

/** @type {string|null} */
let lastObservedActiveQueue = null;

export const clearReadyQueueMembershipMemory = () => {
    readyMembershipMemory.clear();
};

export const resetReadyQueueMembershipMemoryForTests = () => {
    readyMembershipMemory.clear();
    lastObservedActiveQueue = null;
};

export const rememberReadyQueueMembership = (incidentId, state) => {
    const id = Number(incidentId);

    if (!Number.isFinite(id) || id <= 0 || (state !== 'in' && state !== 'out')) {
        return;
    }

    readyMembershipMemory.set(id, state);
};

export const getReadyQueueMembership = (incidentId) => {
    const id = Number(incidentId);

    if (!Number.isFinite(id) || id <= 0) {
        return undefined;
    }

    return readyMembershipMemory.get(id);
};

/**
 * Clear membership memory whenever the operator leaves/enters a different
 * dashboard queue/tab. Does not clear when the active queue is unchanged
 * (Ready UPDATE stays on Ready).
 */
export const syncReadyQueueMembershipScope = (pageRoot, readyQueue = 'action_required') => {
    const activeQueue = pageRoot?.dataset?.liveQueue
        ?? pageRoot?.dataset?.liveFilter
        ?? readyQueue;

    if (lastObservedActiveQueue !== null && lastObservedActiveQueue !== activeQueue) {
        readyMembershipMemory.clear();
    }

    lastObservedActiveQueue = activeQueue;

    return activeQueue;
};
