import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    bindOperatorAlertsChannel,
    handleOperatorAlertRaised,
    resetOperatorAlertDeduplication,
} from '../../resources/js/operator-alerts';

describe('operator alerts', () => {
    beforeEach(() => {
        resetOperatorAlertDeduplication();
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('shows a browser notification and navigates on click', () => {
        const close = vi.fn();
        let clickHandler = null;

        class FakeNotification {
            constructor(title, options) {
                this.title = title;
                this.options = options;
                this.close = close;
            }

            set onclick(handler) {
                clickHandler = handler;
            }
        }

        FakeNotification.permission = 'granted';
        vi.stubGlobal('Notification', FakeNotification);

        const focus = vi.fn();
        window.focus = focus;

        const originalLocation = window.location;
        delete window.location;
        window.location = { href: '' };

        handleOperatorAlertRaised({
            title: 'Incoming Call',
            message: 'Customer Found',
            action_url: '/incidents/42',
            desktop_popup: true,
            play_sound: false,
            deduplication_key: 'ivr:call:1',
        });

        expect(clickHandler).toBeTypeOf('function');
        clickHandler();
        expect(focus).toHaveBeenCalled();
        expect(window.location.href).toBe('/incidents/42');
        expect(close).toHaveBeenCalled();

        window.location = originalLocation;
    });

    it('suppresses duplicate desktop popups by deduplication key', () => {
        const constructed = [];

        class FakeNotification {
            constructor(title, options) {
                constructed.push({ title, options });
                this.close = vi.fn();
            }

            set onclick(_handler) {}
        }

        FakeNotification.permission = 'granted';
        vi.stubGlobal('Notification', FakeNotification);

        const payload = {
            title: 'Alert',
            message: 'Once',
            action_url: '/x',
            desktop_popup: true,
            deduplication_key: 'dup:1',
        };

        handleOperatorAlertRaised(payload);
        handleOperatorAlertRaised(payload);

        expect(constructed).toHaveLength(1);
    });

    it('binds OperatorAlertRaised on the notifications channel', () => {
        const listen = vi.fn();
        bindOperatorAlertsChannel({ listen });

        expect(listen).toHaveBeenCalledWith('.OperatorAlertRaised', expect.any(Function));
    });
});
