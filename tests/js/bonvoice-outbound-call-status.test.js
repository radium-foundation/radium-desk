import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    bindOutboundClickToCallStatusChannel,
    handleOutboundClickToCallStatusUpdated,
    lifecycleStatusLabel,
    resetOutboundClickToCallTracking,
    trackOutboundClickToCall,
} from '../../resources/js/bonvoice-outbound-call-status';

describe('bonvoice outbound call status', () => {
    beforeEach(() => {
        document.body.innerHTML = `
            <button type="button" data-bonvoice-click-to-call>
                <span data-bonvoice-call-status-label>Call</span>
            </button>
        `;
        vi.useFakeTimers();
    });

    afterEach(() => {
        resetOutboundClickToCallTracking();
        vi.useRealTimers();
    });

    it('maps lifecycle statuses to operator-facing labels', () => {
        expect(lifecycleStatusLabel('calling')).toBe('Calling...');
        expect(lifecycleStatusLabel('ringing')).toBe('Ringing...');
        expect(lifecycleStatusLabel('answered')).toBe('Connected');
        expect(lifecycleStatusLabel('no_answer')).toBe('No Answer');
    });

    it('updates only the active outbound call button', () => {
        const button = document.querySelector('[data-bonvoice-click-to-call]');

        trackOutboundClickToCall({ eventId: 'EVT1234567890ABCD', button });
        expect(button.querySelector('[data-bonvoice-call-status-label]').textContent).toBe('Calling...');

        handleOutboundClickToCallStatusUpdated({
            call: {
                event_id: 'OTHER-EVENT',
                lifecycle_status: 'ringing',
            },
        });
        expect(button.querySelector('[data-bonvoice-call-status-label]').textContent).toBe('Calling...');

        handleOutboundClickToCallStatusUpdated({
            call: {
                event_id: 'EVT1234567890ABCD',
                lifecycle_status: 'ringing',
                terminal: false,
            },
        });
        expect(button.querySelector('[data-bonvoice-call-status-label]').textContent).toBe('Ringing...');
    });

    it('cleans up after terminal lifecycle status', () => {
        const button = document.querySelector('[data-bonvoice-click-to-call]');

        trackOutboundClickToCall({ eventId: 'EVT1234567890ABCD', button });

        handleOutboundClickToCallStatusUpdated({
            call: {
                event_id: 'EVT1234567890ABCD',
                lifecycle_status: 'no_answer',
                terminal: true,
            },
        });

        expect(button.querySelector('[data-bonvoice-call-status-label]').textContent).toBe('No Answer');

        vi.advanceTimersByTime(2600);

        expect(button.querySelector('[data-bonvoice-call-status-label]').textContent).toBe('Call');
        expect(button.disabled).toBe(false);
    });

    it('binds only one listener per notifications channel', () => {
        const listeners = new Map();
        const channelA = {
            listen: vi.fn((event, handler) => {
                listeners.set(event, handler);
            }),
            stopListening: vi.fn(),
        };
        const channelB = {
            listen: vi.fn((event, handler) => {
                listeners.set(event, handler);
            }),
            stopListening: vi.fn(),
        };

        bindOutboundClickToCallStatusChannel(channelA);
        bindOutboundClickToCallStatusChannel(channelA);
        expect(channelA.listen).toHaveBeenCalledTimes(1);

        bindOutboundClickToCallStatusChannel(channelB);
        expect(channelA.stopListening).toHaveBeenCalledWith(
            '.OutboundClickToCallStatusUpdated',
            listeners.get('.OutboundClickToCallStatusUpdated'),
        );
        expect(channelB.listen).toHaveBeenCalledTimes(1);
    });
});
