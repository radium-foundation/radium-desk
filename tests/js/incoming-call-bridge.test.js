import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    buildCallPayloadFromInteraction,
    maybeShowIncomingCallCardFromNotification,
    renderIncomingCallNotification,
    resetIncomingCallPopupTerminalState,
    resolveIncomingCallPayload,
} from '../../resources/js/incoming-call-bridge';
import { initIncomingCallCardHost, showIncomingCallCard } from '../../resources/js/incoming-call-card';
import { handleIncomingCallReceived } from '../../resources/js/realtime-notifications';
import { handleNotificationCreated } from '../../resources/js/live-dashboard-reverb';
import { pollNotifications } from '../../resources/js/live-notifications';

const ringingInteraction = {
    channel: 'phone',
    direction: 'inbound',
    status: 'ringing',
    call_id: 'call-bridge-001',
    incident_id: 42,
    customer_phone: '9876543210',
    customer_name: 'Known Customer',
    reference_label: 'SC00042',
};

const incomingCallPayload = {
    call_id: 'call-bridge-001',
    customer_name: 'Known Customer',
    mobile_number: '9876543210',
    call_status: 'ringing',
    assigned_operator: 'Agent One',
    received_at: '2026-07-25T12:00:00.000Z',
    incident_id: 42,
    action_url: '/dashboard?open_customer_360=42',
};

describe('incoming call bridge', () => {
    beforeEach(() => {
        resetIncomingCallPopupTerminalState();
        document.body.innerHTML = '<div id="incoming-call-card-host"></div>';
        initIncomingCallCardHost();
    });

    it('builds call payload from phone interaction', () => {
        expect(buildCallPayloadFromInteraction(ringingInteraction)).toEqual({
            call_id: 'call-bridge-001',
            customer_name: 'Known Customer',
            mobile_number: '9876543210',
            call_status: 'ringing',
            assigned_operator: null,
            received_at: expect.any(String),
            incident_id: 42,
            action_url: '/dashboard?open_customer_360=42',
        });
    });

    it('prefers direct call payload when present', () => {
        expect(resolveIncomingCallPayload({ call: incomingCallPayload })).toEqual(incomingCallPayload);
    });

    it('renders from IncomingCallReceived payload', () => {
        renderIncomingCallNotification({ call: incomingCallPayload });

        expect(document.querySelectorAll('[data-call-id="call-bridge-001"]')).toHaveLength(1);
        expect(document.querySelector('.incoming-call-card')?.textContent).toContain('Known Customer');
    });

    it('renders from NotificationCreated interaction payload', () => {
        maybeShowIncomingCallCardFromNotification({
            title: '📞 Incoming Call',
            message: 'Customer Found: RD3444319',
            url: '/incidents/42',
            interaction: ringingInteraction,
        });

        expect(document.querySelectorAll('[data-call-id="call-bridge-001"]')).toHaveLength(1);
    });

    it('does not render duplicate cards for the same call id', () => {
        maybeShowIncomingCallCardFromNotification({ call: incomingCallPayload });
        maybeShowIncomingCallCardFromNotification({ interaction: ringingInteraction });

        expect(document.querySelectorAll('[data-call-id="call-bridge-001"]')).toHaveLength(1);
    });

    it('rebuilds Open URL on duplicate ringing delivery when incident becomes available', () => {
        maybeShowIncomingCallCardFromNotification({
            call: {
                ...incomingCallPayload,
                incident_id: null,
                action_url: '/dashboard',
            },
        });

        maybeShowIncomingCallCardFromNotification({
            interaction: {
                ...ringingInteraction,
                status: 'ringing',
                incident_id: 42,
            },
            url: '/dashboard?open_customer_360=42',
        });

        expect(document.querySelectorAll('[data-call-id="call-bridge-001"]')).toHaveLength(1);
        expect(document.querySelector('[data-call-id="call-bridge-001"] a.btn-primary')?.getAttribute('href'))
            .toBe('/dashboard?open_customer_360=42');
    });

    it('dismisses the card for answered interactions and does not recreate it', () => {
        maybeShowIncomingCallCardFromNotification({ call: incomingCallPayload });

        maybeShowIncomingCallCardFromNotification({
            interaction: {
                ...ringingInteraction,
                status: 'answered',
            },
        });

        expect(document.querySelector('[data-call-id="call-bridge-001"]')).toBeNull();

        maybeShowIncomingCallCardFromNotification({ call: incomingCallPayload });

        expect(document.querySelector('[data-call-id="call-bridge-001"]')).toBeNull();
    });

    it('dismisses the card for missed interactions', () => {
        maybeShowIncomingCallCardFromNotification({ call: incomingCallPayload });

        maybeShowIncomingCallCardFromNotification({
            interaction: {
                ...ringingInteraction,
                status: 'missed',
            },
        });

        expect(document.querySelector('[data-call-id="call-bridge-001"]')).toBeNull();
    });

    it('does not recreate a ringing card after the call was marked terminal', () => {
        showIncomingCallCard(incomingCallPayload);
        maybeShowIncomingCallCardFromNotification({
            interaction: {
                ...ringingInteraction,
                status: 'answered',
            },
        });

        maybeShowIncomingCallCardFromNotification({ call: incomingCallPayload });

        expect(document.querySelector('[data-call-id="call-bridge-001"]')).toBeNull();
    });

    it('handleIncomingCallReceived still renders the card', () => {
        handleIncomingCallReceived({ call: incomingCallPayload });

        expect(document.querySelector('[data-call-id="call-bridge-001"]')).not.toBeNull();
    });

    it('handleNotificationCreated also renders the card', () => {
        document.body.innerHTML = `
            <div id="incoming-call-card-host"></div>
            <div id="notification-bell-root"></div>
            <div id="dashboard-page"></div>
        `;
        initIncomingCallCardHost();

        handleNotificationCreated(document.getElementById('dashboard-page'), {
            title: '📞 Incoming Call',
            message: 'Customer Found: RD3444319',
            url: '/incidents/42',
            interaction: ringingInteraction,
        });

        expect(document.querySelector('[data-call-id="call-bridge-001"]')).not.toBeNull();
    });

    it('pollNotifications renders incoming call cards from polled notifications', async () => {
        document.body.innerHTML = `
            <div id="incoming-call-card-host"></div>
            <div id="notification-bell-root" data-poll-url="/notifications/poll"></div>
        `;
        initIncomingCallCardHost();

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                unread_count: 1,
                bell_html: '<div class="dropdown"><button type="button" class="notification-bell-btn"></button></div>',
                new_notifications: [{
                    id: 'notification-1',
                    title: '📞 Incoming Call',
                    message: 'Customer Found: RD3444319',
                    url: '/incidents/42',
                    interaction: ringingInteraction,
                }],
            }),
        }));

        await pollNotifications({ unreadCount: 0, since: '2026-07-25T11:00:00.000Z' });

        expect(document.querySelector('[data-call-id="call-bridge-001"]')).not.toBeNull();

        vi.unstubAllGlobals();
    });
});
