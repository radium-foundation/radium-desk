import { adjustFilterCount } from './live-dashboard';
import {
    clearReadyQueueMembershipMemory,
    getReadyQueueMembership,
    rememberReadyQueueMembership,
    resetReadyQueueMembershipMemoryForTests,
    syncReadyQueueMembershipScope,
} from './ready-queue-membership-memory';

/** Authoritative Ready Queue filter key (`service_case_filter_counts.action_required`). */
export const READY_QUEUE = 'action_required';

export {
    clearReadyQueueMembershipMemory,
    rememberReadyQueueMembership,
    syncReadyQueueMembershipScope,
};

export const resetReadyQueueCountDeltaForTests = () => {
    resetReadyQueueMembershipMemoryForTests();
};

export const isViewingReadyQueue = (pageRoot) => {
    const activeQueue = syncReadyQueueMembershipScope(pageRoot, READY_QUEUE);

    return activeQueue === READY_QUEUE;
};

export const serviceCaseRowPresent = (incidentId) => {
    const id = Number(incidentId);

    if (!Number.isFinite(id) || id <= 0) {
        return false;
    }

    return Boolean(document.getElementById(`service-case-row-${id}`));
};

/**
 * Prove a Ready Queue count delta from classic Ably `list_actions.action_required`
 * plus DOM presence (loaded Ready rows). Does not invent membership rules.
 *
 * Safe only while the operator is viewing Ready Queue — the DOM then reflects
 * Ready membership for loaded rows. `list_actions.add` means upsert/visible in
 * primary queue, not "newly entered"; presence distinguishes ADD vs UPDATE.
 *
 * @returns {{ safe: boolean, delta: number, reason: string }}
 */
export const resolveReadyQueueCountDeltaFromListAction = ({
    pageRoot,
    incidentId,
    listActions,
} = {}) => {
    if (!isViewingReadyQueue(pageRoot)) {
        return { safe: false, delta: 0, reason: 'not_viewing_ready' };
    }

    if (!listActions || typeof listActions !== 'object'
        || !Object.prototype.hasOwnProperty.call(listActions, READY_QUEUE)) {
        return { safe: false, delta: 0, reason: 'missing_ready_list_action' };
    }

    const id = Number(incidentId);

    if (!Number.isFinite(id) || id <= 0) {
        return { safe: false, delta: 0, reason: 'bad_incident' };
    }

    const readyAction = listActions[READY_QUEUE];
    const present = serviceCaseRowPresent(id);
    const remembered = getReadyQueueMembership(id);

    if (readyAction === 'remove') {
        if (remembered === 'out') {
            return { safe: true, delta: 0, reason: 'duplicate_remove' };
        }

        if (present || remembered === 'in') {
            return { safe: true, delta: -1, reason: 'remove' };
        }

        // Absent row and no prior ADD memory: unloaded Ready member may have left
        // — cannot prove. Leave absolute reconcile.
        return { safe: false, delta: 0, reason: 'remove_not_in_dom' };
    }

    if (readyAction === 'update') {
        return { safe: true, delta: 0, reason: 'update' };
    }

    if (readyAction === 'add') {
        if (present || remembered === 'in') {
            // Primary-queue upsert / SLA refresh / duplicate ADD — not a count change.
            return { safe: true, delta: 0, reason: 'add_already_present' };
        }

        return { safe: true, delta: 1, reason: 'add' };
    }

    if (readyAction === 'ignore') {
        return { safe: true, delta: 0, reason: 'ignore' };
    }

    return { safe: false, delta: 0, reason: 'unknown_action' };
};

/**
 * Prove Ready Queue deltas from hybrid `/live/rows` results while viewing Ready.
 * Must run before DOM patch/remove so presence reflects prior membership.
 *
 * @returns {{ safe: boolean, delta: number, reason: string }}
 */
export const resolveReadyQueueCountDeltaFromHybrid = ({
    pageRoot,
    rows,
    removeIncidentIds,
} = {}) => {
    if (!isViewingReadyQueue(pageRoot)) {
        return { safe: false, delta: 0, reason: 'not_viewing_ready' };
    }

    let delta = 0;
    const removes = Array.isArray(removeIncidentIds) ? removeIncidentIds : [];
    const rowList = Array.isArray(rows) ? rows : [];
    const rememberedOut = [];
    const rememberedIn = [];

    for (const rawId of removes) {
        const id = Number(rawId);

        if (!Number.isFinite(id) || id <= 0) {
            continue;
        }

        if (getReadyQueueMembership(id) === 'out') {
            continue;
        }

        if (!serviceCaseRowPresent(id) && getReadyQueueMembership(id) !== 'in') {
            return { safe: false, delta: 0, reason: 'remove_not_in_dom' };
        }

        delta -= 1;
        rememberedOut.push(id);
    }

    for (const row of rowList) {
        const id = Number(row?.incident_id ?? row?.id);

        if (!Number.isFinite(id) || id <= 0) {
            continue;
        }

        if (!serviceCaseRowPresent(id) && getReadyQueueMembership(id) !== 'in') {
            delta += 1;
            rememberedIn.push(id);
        }
    }

    return {
        safe: true,
        delta,
        reason: 'hybrid',
        rememberIn: rememberedIn,
        rememberOut: rememberedOut,
    };
};

export const applyReadyQueueCountDelta = (delta, {
    rememberIn = [],
    rememberOut = [],
} = {}) => {
    rememberIn.forEach((id) => rememberReadyQueueMembership(id, 'in'));
    rememberOut.forEach((id) => rememberReadyQueueMembership(id, 'out'));

    if (!delta) {
        return null;
    }

    return adjustFilterCount(READY_QUEUE, delta);
};

/**
 * Apply a proven classic list_action delta and record membership for dedupe.
 */
export const commitReadyQueueListActionDelta = (resolution, incidentId) => {
    if (!resolution?.safe) {
        return null;
    }

    if (resolution.delta > 0) {
        return applyReadyQueueCountDelta(resolution.delta, {
            rememberIn: [incidentId],
        });
    }

    if (resolution.delta < 0) {
        return applyReadyQueueCountDelta(resolution.delta, {
            rememberOut: [incidentId],
        });
    }

    if (resolution.reason === 'add_already_present' || resolution.reason === 'update') {
        rememberReadyQueueMembership(incidentId, 'in');
    }

    if (resolution.reason === 'duplicate_remove') {
        rememberReadyQueueMembership(incidentId, 'out');
    }

    return null;
};
