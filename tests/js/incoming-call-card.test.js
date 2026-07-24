import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    initIncomingCallCardHost,
    logIncomingCallPopupLatency,
    showIncomingCallCard,
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
