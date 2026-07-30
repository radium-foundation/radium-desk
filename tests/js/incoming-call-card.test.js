import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    dismissIncomingCallCard,
    initIncomingCallCardHost,
    logIncomingCallPopupLatency,
    showIncomingCallCard,
    updateIncomingCallCard,
} from '../../resources/js/incoming-call-card';

describe('incoming call card latency', () => {
    beforeEach(() => {
        document.body.innerHTML = '<div id="incoming-call-card-host"></div>';
        initIncomingCallCardHost();
        vi.spyOn(console, 'info').mockImplementation(() => {});
    });

    afterEach(() => {
        document.body.innerHTML = '';
        vi.restoreAllMocks();
    });

    it('logs S7 browser latency when showing a card with received_at', () => {
        const receivedAt = new Date(Date.now() - 400).toISOString();

        showIncomingCallCard({
            call_id: 'call-lat-1',
            customer_name: 'Test',
            mobile_number: '9999999999',
            call_status: 'ringing',
            received_at: receivedAt,
            incident_id: 12,
            action_url: '/dashboard?open_customer_360=12',
        });

        expect(console.info).toHaveBeenCalledWith(
            '[BonVoice Incoming Latency]',
            expect.objectContaining({
                stage: 'S7_browser_popup',
                call_id: 'call-lat-1',
                incident_id: 12,
                received_at: receivedAt,
            }),
        );

        const payload = console.info.mock.calls[0][1];
        expect(payload.total_ms).toBeGreaterThanOrEqual(0);
        expect(payload.duration_ms).toBe(payload.total_ms);
        expect(document.querySelector('[data-call-id="call-lat-1"]')).not.toBeNull();
    });

    it('skips S7 log when received_at is missing', () => {
        logIncomingCallPopupLatency({ call_id: 'call-lat-2' });

        expect(console.info).not.toHaveBeenCalled();
    });
});

describe('incoming call card lifecycle', () => {
    beforeEach(() => {
        document.body.innerHTML = '<div id="incoming-call-card-host"></div>';
        initIncomingCallCardHost();
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    const ringingCall = {
        call_id: 'call-life-1',
        customer_name: 'Unknown caller',
        mobile_number: '9111222333',
        call_status: 'ringing',
        incident_id: null,
        action_url: '/dashboard',
    };

    it('dismisses the card for a call id', () => {
        showIncomingCallCard(ringingCall);

        expect(dismissIncomingCallCard('call-life-1')).toBe(true);
        expect(document.querySelector('[data-call-id="call-life-1"]')).toBeNull();
        expect(dismissIncomingCallCard('call-life-1')).toBe(false);
    });

    it('rebuilds Open URL when incident_id becomes available', () => {
        showIncomingCallCard(ringingCall);

        updateIncomingCallCard({
            ...ringingCall,
            call_status: 'answered',
            incident_id: 99,
            action_url: '/dashboard?open_customer_360=99',
        });

        const openLink = document.querySelector('[data-call-id="call-life-1"] a.btn-primary');
        expect(openLink?.getAttribute('href')).toBe('/dashboard?open_customer_360=99');
        expect(document.querySelector('[data-call-id="call-life-1"]')?.dataset.incidentId).toBe('99');
    });

    it('only updates badge when incident and action url are unchanged', () => {
        showIncomingCallCard({
            ...ringingCall,
            incident_id: 42,
            action_url: '/dashboard?open_customer_360=42',
        });

        const cardBefore = document.querySelector('[data-call-id="call-life-1"]');

        updateIncomingCallCard({
            call_id: 'call-life-1',
            call_status: 'answered',
            incident_id: 42,
            action_url: '/dashboard?open_customer_360=42',
        });

        const cardAfter = document.querySelector('[data-call-id="call-life-1"]');
        expect(cardAfter).toBe(cardBefore);
        expect(cardAfter?.querySelector('.badge')?.textContent).toBe('answered');
    });
});
