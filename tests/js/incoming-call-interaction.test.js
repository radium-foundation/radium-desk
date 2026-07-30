import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    maybeHandleIncomingCallInteraction,
    resetIncomingCallInteractionState,
} from '../../resources/js/incoming-call-interaction';
import { getWorkspaceSession, resetWorkspaceSession } from '../../resources/js/workspace/session';
import { initIncomingCallCardHost, showIncomingCallCard } from '../../resources/js/incoming-call-card';

describe('incoming call interaction auto-open', () => {
    beforeEach(() => {
        resetWorkspaceSession();
        resetIncomingCallInteractionState();
        document.body.innerHTML = '<div id="incoming-call-card-host"></div>';
        initIncomingCallCardHost();
        vi.spyOn(document, 'dispatchEvent').mockImplementation(() => true);
    });

    afterEach(() => {
        resetWorkspaceSession();
        resetIncomingCallInteractionState();
        document.body.innerHTML = '';
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
        conversation_workspace: false,
    };

    it('dispatches customer360:open for answered inbound phone call with incident', () => {
        showIncomingCallCard({
            call_id: 'call-001',
            call_status: 'ringing',
            action_url: '/dashboard?open_customer_360=42',
            incident_id: 42,
        });

        maybeHandleIncomingCallInteraction(answeredInteraction);

        const openEvent = document.dispatchEvent.mock.calls
            .map(([event]) => event)
            .find((event) => event.type === 'customer360:open');

        expect(openEvent).toBeDefined();
        expect(openEvent.detail).toEqual({
            incidentId: 42,
            referenceLabel: 'SC00042',
            callId: 'call-001',
            conversationWorkspace: false,
        });
        expect(document.querySelector('[data-call-id="call-001"]')).toBeNull();
    });

    it('opens conversation workspace for new enquiry answered calls and dismisses popup', () => {
        showIncomingCallCard({
            call_id: 'call-cw-1',
            call_status: 'ringing',
            action_url: '/dashboard',
            incident_id: null,
        });

        maybeHandleIncomingCallInteraction({
            ...answeredInteraction,
            call_id: 'call-cw-1',
            incident_id: 77,
            conversation_workspace: true,
            reference_label: 'SC00077',
        });

        const openEvent = document.dispatchEvent.mock.calls
            .map(([event]) => event)
            .find((event) => event.type === 'customer360:open');

        expect(openEvent.detail.conversationWorkspace).toBe(true);
        expect(openEvent.detail.incidentId).toBe(77);
        expect(document.querySelector('[data-call-id="call-cw-1"]')).toBeNull();
    });

    it('dismisses popup on missed without opening Customer360', () => {
        showIncomingCallCard({
            call_id: 'call-missed-1',
            call_status: 'ringing',
            action_url: '/dashboard',
        });

        maybeHandleIncomingCallInteraction({
            channel: 'phone',
            direction: 'inbound',
            status: 'missed',
            call_id: 'call-missed-1',
            incident_id: null,
        });

        expect(document.dispatchEvent).not.toHaveBeenCalled();
        expect(document.querySelector('[data-call-id="call-missed-1"]')).toBeNull();
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

    it('refreshes Open URL when workspace is busy instead of auto-opening', () => {
        getWorkspaceSession().acquire('workspace-modal');
        showIncomingCallCard({
            call_id: 'call-001',
            call_status: 'ringing',
            action_url: '/dashboard',
            incident_id: null,
        });

        maybeHandleIncomingCallInteraction(answeredInteraction);

        expect(document.dispatchEvent).not.toHaveBeenCalled();
        expect(document.querySelector('[data-call-id="call-001"] a.btn-primary')?.getAttribute('href'))
            .toBe('/dashboard?open_customer_360=42');
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
