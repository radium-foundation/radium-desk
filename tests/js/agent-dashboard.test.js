import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    CUSTOMER360_APPOINTMENT_ANCHOR,
    CUSTOMER360_APPOINTMENT_TAB,
    dismissAppointmentBanner,
    initAgentDashboard,
    openCustomer360ForAppointment,
} from '../../resources/js/agent-dashboard';

describe('agent dashboard polish', () => {
    beforeEach(() => {
        vi.stubGlobal('localStorage', {
            getItem: vi.fn(() => null),
            setItem: vi.fn(),
            removeItem: vi.fn(),
        });
        document.body.innerHTML = `
            <div id="dashboard-page"
                 data-next-appointment='{"incident_id":42,"customer_name":"Rakesh Sharma","starts_at":"2026-07-06T06:30:00.000Z","time_label":"12:00 PM","starts_in_label":"Starts in 30 minutes","is_overdue":false,"is_imminent":true}'>
                <div class="agent-appointment-banner-sticky-host"
                     data-agent-appointment-sticky
                     data-incident-id="42"></div>
                <button type="button" data-agent-open-customer-360="42" data-agent-customer-name="Rakesh Sharma" data-agent-open-appointment="true">
                    Open
                </button>
            </div>
        `;
    });

    afterEach(() => {
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
    });

    it('opens customer360 on the appointment section when appointment trigger is clicked', () => {
        const pageRoot = document.getElementById('dashboard-page');
        const handler = vi.fn();

        document.addEventListener('customer360:open', handler);
        const dashboard = initAgentDashboard({ pageRoot });

        pageRoot.querySelector('[data-agent-open-customer-360]')?.dispatchEvent(new MouseEvent('click', { bubbles: true }));

        expect(handler).toHaveBeenCalledTimes(1);
        expect(handler.mock.calls[0][0].detail).toEqual({
            incidentId: '42',
            referenceLabel: 'Rakesh Sharma',
            tab: CUSTOMER360_APPOINTMENT_TAB,
            anchor: CUSTOMER360_APPOINTMENT_ANCHOR,
        });

        dashboard?.destroy();
    });

    it('dispatches appointment-focused customer360 open helper', () => {
        const pageRoot = document.getElementById('dashboard-page');
        const handler = vi.fn();

        document.addEventListener('customer360:open', handler);
        openCustomer360ForAppointment(pageRoot, '42', 'Rakesh Sharma');

        expect(handler.mock.calls[0][0].detail.tab).toBe('overview');
        expect(handler.mock.calls[0][0].detail.anchor).toBe('support-appointments');
    });

    it('dismisses sticky appointment banner after customer360 opens for that incident', () => {
        const pageRoot = document.getElementById('dashboard-page');
        const stickyHost = pageRoot.querySelector('[data-agent-appointment-sticky]');

        const dashboard = initAgentDashboard({ pageRoot });

        document.dispatchEvent(new CustomEvent('customer360:open', {
            detail: { incidentId: '42', referenceLabel: 'Rakesh Sharma' },
        }));

        expect(stickyHost?.classList.contains('is-dismissed')).toBe(true);

        dashboard?.destroy();
    });

    it('persists banner dismissal across reloads', () => {
        const pageRoot = document.getElementById('dashboard-page');

        dismissAppointmentBanner(pageRoot, '42');

        expect(localStorage.setItem).toHaveBeenCalledWith('radium.agent.appointmentBanner.dismissed.42', '1');
    });
});

describe('browser appointment notification', () => {
    beforeEach(() => {
        vi.stubGlobal('localStorage', {
            getItem: vi.fn(() => null),
            setItem: vi.fn(),
            removeItem: vi.fn(),
        });
        document.body.innerHTML = `
            <div id="dashboard-page"
                 data-next-appointment='{"incident_id":77,"customer_name":"Rakesh Sharma","starts_at":"2026-07-06T06:30:00.000Z","time_label":"12:00 PM","starts_in_label":"Starts in 10 minutes","is_overdue":false,"is_imminent":true}'>
            </div>
        `;
    });

    afterEach(() => {
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
    });

    it('opens customer360 on the appointment section when notification is clicked', () => {
        const pageRoot = document.getElementById('dashboard-page');
        const handler = vi.fn();
        const notification = { close: vi.fn() };

        document.addEventListener('customer360:open', handler);

        vi.stubGlobal('Notification', Object.assign(function Notification() {
            return notification;
        }, {
            permission: 'granted',
        }));

        notification.onclick = null;

        const showBrowserAppointmentNotification = (appointment, root) => {
            const instance = new Notification('Upcoming Appointment', {
                body: `Customer: ${appointment.customerName}\nTime: ${appointment.timeLabel}`,
            });

            instance.onclick = () => {
                window.focus();
                openCustomer360ForAppointment(root, appointment.incidentId, appointment.customerName);
                instance.close();
            };

            instance.onclick();

            return true;
        };

        showBrowserAppointmentNotification({
            incidentId: '77',
            customerName: 'Rakesh Sharma',
            timeLabel: '12:00 PM',
        }, pageRoot);

        expect(handler.mock.calls[0][0].detail).toMatchObject({
            incidentId: '77',
            tab: CUSTOMER360_APPOINTMENT_TAB,
            anchor: CUSTOMER360_APPOINTMENT_ANCHOR,
        });

        vi.unstubAllGlobals();
    });
});
