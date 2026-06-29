export const SESSION_REASONS = [
    'workspace-modal',
    'inline-transaction',
    'bulk-selection',
    'quick-create',
    'notification-dropdown',
    'customer-360-drawer',
];

const recomputeLockedIncidentIds = (reasons) => {
    const lockedIncidentIds = new Set();

    reasons.forEach((metadata) => {
        if (metadata?.incidentId !== undefined) {
            lockedIncidentIds.add(Number(metadata.incidentId));
        }

        (metadata?.incidentIds ?? []).forEach((incidentId) => {
            lockedIncidentIds.add(Number(incidentId));
        });
    });

    return lockedIncidentIds;
};

export const createWorkspaceSession = () => {
    /** @type {Map<string, object>} */
    const reasons = new Map();
    /** @type {Set<() => void>} */
    const idleCallbacks = new Set();
    let lockedIncidentIds = new Set();

    const acquire = (reason, metadata = {}) => {
        if (!SESSION_REASONS.includes(reason)) {
            return;
        }

        reasons.set(reason, metadata);
        lockedIncidentIds = recomputeLockedIncidentIds(reasons);
    };

    const release = (reason) => {
        if (!reasons.has(reason)) {
            return;
        }

        reasons.delete(reason);
        lockedIncidentIds = recomputeLockedIncidentIds(reasons);

        if (reasons.size === 0) {
            idleCallbacks.forEach((callback) => {
                callback();
            });
        }
    };

    const isActive = (reason = null) => {
        if (reason) {
            return reasons.has(reason);
        }

        return reasons.size > 0;
    };

    const getActiveReasons = () => Array.from(reasons.keys());

    const onIdle = (callback) => {
        idleCallbacks.add(callback);

        return () => {
            idleCallbacks.delete(callback);
        };
    };

    const getLockedIncidentIds = () => Array.from(lockedIncidentIds);

    return {
        acquire,
        release,
        isActive,
        getActiveReasons,
        onIdle,
        getLockedIncidentIds,
    };
};

let sessionInstance = null;

export const getWorkspaceSession = () => {
    if (!sessionInstance) {
        sessionInstance = createWorkspaceSession();
    }

    return sessionInstance;
};

export const resetWorkspaceSession = () => {
    sessionInstance = null;
};
