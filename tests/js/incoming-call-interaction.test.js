import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    maybeHandleIncomingCallInteraction,
    resetIncomingCallInteractionState,
} from '../../resources/js/incoming-call-interaction';
import { getWorkspaceSession, resetWorkspaceSession } from '../../resources/js/workspace/session';

describe('incoming call interaction auto-open', () => {
    beforeEach(() => {
        resetWorkspaceSession();
        resetIncomingCallInteractionState();
        vi.spyOn(document, 'dispatchEvent').mockImplementation(() => true);
    });

    afterEach(() => {
        resetWorkspaceSession();
        resetIncomingCallInteractionState();
        vi.restoreAllMocks();
    });

    const answeredInteraction = {
        channel: 'phone',
        direction: 'inbound',
        status: 'answered',
        call_id: 'call-001',
        incident_id: 42,
        customer_phone: '9876543210',
        customer_name: 'Known Customer',
        reference_label: 'SC00042',
    };

    it('dispatches customer360:open for answered inbound phone call with incident', () => {
        maybeHandleIncomingCallInteraction(answeredInteraction);

        const openEvent = document.dispatchEvent.mock.calls
            .map(([event]) => event)
            .find((event) => event.type === 'customer360:open');

        expect(openEvent).toBeDefined();
        expect(openEvent.detail).toEqual({
            incidentId: 42,
            referenceLabel: 'SC00042',
        });
    });

    it('does nothing for ringing status', () => {
        maybeHandleIncomingCallInteraction({
            ...answeredInteraction,
            status: 'ringing',
        });

        expect(document.dispatchEvent).not.toHaveBeenCalled();
    });

    it('does nothing when incident is missing', () => {
        maybeHandleIncomingCallInteraction({
            ...answeredInteraction,
            incident_id: null,
        });

        expect(document.dispatchEvent).not.toHaveBeenCalled();
    });

    it('does nothing when workspace session is active', () => {
        getWorkspaceSession().acquire('workspace-modal');

        maybeHandleIncomingCallInteraction(answeredInteraction);

        expect(document.dispatchEvent).not.toHaveBeenCalled();
    });

    it('does nothing for unknown customer without incident', () => {
        maybeHandleIncomingCallInteraction({
            channel: 'phone',
            direction: 'inbound',
            status: 'answered',
            call_id: 'call-unknown',
            incident_id: null,
            customer_phone: '9111222333',
        });

        expect(document.dispatchEvent).not.toHaveBeenCalled();
    });

    it('does not dispatch twice for the same call_id', () => {
        maybeHandleIncomingCallInteraction(answeredInteraction);
        document.dispatchEvent.mockClear();
        maybeHandleIncomingCallInteraction(answeredInteraction);

        const openEvents = document.dispatchEvent.mock.calls
            .map(([event]) => event)
            .filter((event) => event.type === 'customer360:open');

        expect(openEvents).toHaveLength(0);
    });

    it('does nothing for malformed incident_id', () => {
        maybeHandleIncomingCallInteraction({
            ...answeredInteraction,
            call_id: 'call-malformed',
            incident_id: 'not-a-number',
        });

        expect(document.dispatchEvent).not.toHaveBeenCalled();
    });

    it('allows a later call_id after the first call was processed', () => {
        maybeHandleIncomingCallInteraction(answeredInteraction);

        maybeHandleIncomingCallInteraction({
            ...answeredInteraction,
            call_id: 'call-002',
        });

        const openEvents = document.dispatchEvent.mock.calls
            .map(([event]) => event)
            .filter((event) => event.type === 'customer360:open');

        expect(openEvents).toHaveLength(2);
        expect(openEvents[1].detail.incidentId).toBe(42);
    });
});
