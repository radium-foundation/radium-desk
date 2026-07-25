import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    applyLiveRefreshNextAppointment,
    CUSTOMER360_APPOINTMENT_ANCHOR,
    CUSTOMER360_APPOINTMENT_TAB,
    dismissAppointmentBanner,
    initAgentDashboard,
    openCustomer360ForAppointment,
    rememberLastCustomer,
} from '../../resources/js/agent-dashboard';
import { applyPartialDashboardUpdate } from '../../resources/js/live-dashboard';

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

    it('renders recent customer shortcuts from storage', () => {
        const storage = new Map([
            ['radium.agent.recentCustomers', JSON.stringify([
                { incidentId: '101', referenceLabel: 'SC10101', openedAt: '2026-07-12T10:00:00.000Z' },
                { incidentId: '98', referenceLabel: 'SC10098', openedAt: '2026-07-12T09:00:00.000Z' },
                { incidentId: '81', referenceLabel: 'SC10081', openedAt: '2026-07-12T08:00:00.000Z' },
            ])],
        ]);

        vi.stubGlobal('localStorage', {
            getItem: vi.fn((key) => storage.get(key) ?? null),
            setItem: vi.fn((key, value) => storage.set(key, value)),
            removeItem: vi.fn((key) => storage.delete(key)),
        });

        document.body.innerHTML = `
            <div id="dashboard-page">
                <div class="dashboard-recent-customers d-none"
                     data-agent-recent-customers
                     hidden>
                    <span class="dashboard-recent-customers__label">Recent Customers</span>
                    <div class="dashboard-recent-customers__chips"
                         data-agent-recent-customers-list></div>
                </div>
            </div>
        `;

        const pageRoot = document.getElementById('dashboard-page');
        const host = pageRoot.querySelector('[data-agent-recent-customers]');
        const dashboard = initAgentDashboard({ pageRoot });

        expect(host?.hidden).toBe(false);
        expect(host?.querySelectorAll('.dashboard-recent-customers__chip')).toHaveLength(3);
        expect(host?.textContent).toContain('SC10101');
        expect(host?.textContent).toContain('SC10098');
        expect(host?.textContent).toContain('SC10081');

        dashboard?.destroy();
    });

    it('stores up to three recent customers with most recent first', () => {
        const storage = new Map();

        vi.stubGlobal('localStorage', {
            getItem: vi.fn((key) => storage.get(key) ?? null),
            setItem: vi.fn((key, value) => storage.set(key, value)),
            removeItem: vi.fn((key) => storage.delete(key)),
        });

        rememberLastCustomer('1', 'SC10001');
        rememberLastCustomer('2', 'SC10002');
        rememberLastCustomer('3', 'SC10003');
        rememberLastCustomer('4', 'SC10004');
        rememberLastCustomer('2', 'SC10002');

        const recent = JSON.parse(storage.get('radium.agent.recentCustomers') ?? '[]');

        expect(recent).toHaveLength(3);
        expect(recent.map((entry) => entry.referenceLabel)).toEqual([
            'SC10002',
            'SC10004',
            'SC10003',
        ]);
    });
});

describe('dashboard live refresh appointment guard', () => {
    const appointment = {
        incident_id: 42,
        customer_name: 'Rakesh Sharma',
        starts_at: '2026-07-06T06:30:00.000Z',
        time_label: '12:00 PM',
        starts_in_label: 'Starts in 30 minutes',
        is_overdue: false,
        is_imminent: true,
    };

    beforeEach(() => {
        vi.stubGlobal('localStorage', {
            getItem: vi.fn(() => null),
            setItem: vi.fn(),
            removeItem: vi.fn(),
        });
        document.body.innerHTML = `
            <div id="dashboard-page"
                 data-next-appointment='${JSON.stringify(appointment)}'>
                <div class="agent-appointment-banner-sticky-host"
                     data-agent-appointment-sticky
                     data-incident-id="42"></div>
            </div>
        `;
    });

    afterEach(() => {
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
    });

    it('preserves appointment state when live refresh omits next_appointment', () => {
        const pageRoot = document.getElementById('dashboard-page');
        const dashboard = initAgentDashboard({ pageRoot });

        applyLiveRefreshNextAppointment(dashboard, {
            kpi_strip_html: 'stats-live',
            service_case_filter_counts: { action_required: 3 },
        });

        expect(pageRoot.getAttribute('data-next-appointment')).not.toBeNull();
        expect(JSON.parse(pageRoot.getAttribute('data-next-appointment'))).toMatchObject({
            incident_id: 42,
            customer_name: 'Rakesh Sharma',
        });

        dashboard?.destroy();
    });

    it('updates appointment when live refresh explicitly includes next_appointment', () => {
        const pageRoot = document.getElementById('dashboard-page');
        const dashboard = initAgentDashboard({ pageRoot });
        const updatedAppointment = {
            ...appointment,
            incident_id: 99,
            customer_name: 'Updated Customer',
        };

        applyLiveRefreshNextAppointment(dashboard, {
            next_appointment: updatedAppointment,
            kpi_strip_html: 'stats-live',
        });

        expect(JSON.parse(pageRoot.getAttribute('data-next-appointment'))).toMatchObject({
            incident_id: 99,
            customer_name: 'Updated Customer',
        });

        dashboard?.destroy();
    });

    it('clears appointment when live refresh explicitly sends next_appointment null', () => {
        const pageRoot = document.getElementById('dashboard-page');
        const dashboard = initAgentDashboard({ pageRoot });

        applyLiveRefreshNextAppointment(dashboard, {
            next_appointment: null,
            kpi_strip_html: 'stats-live',
        });

        expect(pageRoot.hasAttribute('data-next-appointment')).toBe(false);

        dashboard?.destroy();
    });

    it('preserves appointment through partial row-only live refresh payloads', () => {
        const pageRoot = document.getElementById('dashboard-page');
        const dashboard = initAgentDashboard({ pageRoot });

        applyLiveRefreshNextAppointment(dashboard, {
            rows: [{ incident_id: 10, html: '<tr id="service-case-row-10"></tr>' }],
        });

        expect(pageRoot.hasAttribute('data-next-appointment')).toBe(true);

        dashboard?.destroy();
    });
});

describe('dashboard partial refresh appointment integration', () => {
    const appointment = {
        incident_id: 42,
        customer_name: 'Rakesh Sharma',
        starts_at: '2026-07-06T06:30:00.000Z',
        time_label: '12:00 PM',
        starts_in_label: 'Starts in 30 minutes',
        is_overdue: false,
        is_imminent: true,
    };

    beforeEach(() => {
        vi.stubGlobal('localStorage', {
            getItem: vi.fn(() => null),
            setItem: vi.fn(),
            removeItem: vi.fn(),
        });
        vi.stubGlobal('requestAnimationFrame', (callback) => {
            callback(0);

            return 1;
        });
        document.body.innerHTML = `
            <div id="dashboard-page"
                 data-next-appointment='${JSON.stringify(appointment)}'>
                <div id="dashboard-kpi-strip">stats-old</div>
                <div class="dashboard-service-cases-card" data-service-cases-loaded="1">
                    <div id="dashboard-service-cases-scroll">
                        <table>
                            <tbody id="dashboard-service-cases-body"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        `;
    });

    afterEach(() => {
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
    });

    const bindAppointmentLiveRefresh = (dashboard) => {
        document.addEventListener('dashboard:live-refresh', (event) => {
            applyLiveRefreshNextAppointment(dashboard, event.detail);
        });
    };

    it('preserves appointment through KPI and row partial dashboard updates', async () => {
        const pageRoot = document.getElementById('dashboard-page');
        const dashboard = initAgentDashboard({ pageRoot });

        bindAppointmentLiveRefresh(dashboard);

        await applyPartialDashboardUpdate({
            kpi_strip_html: 'stats-live',
            service_case_filter_counts: { action_required: 2 },
        });

        expect(pageRoot.hasAttribute('data-next-appointment')).toBe(true);

        await applyPartialDashboardUpdate({
            rows: [{ incident_id: 10, html: '<tr id="service-case-row-10"><td>SC00010</td></tr>' }],
        });

        expect(pageRoot.hasAttribute('data-next-appointment')).toBe(true);

        dashboard?.destroy();
    });

    it('updates appointment on full live refresh payload', async () => {
        const pageRoot = document.getElementById('dashboard-page');
        const dashboard = initAgentDashboard({ pageRoot });

        bindAppointmentLiveRefresh(dashboard);

        await applyPartialDashboardUpdate({
            kpi_strip_html: 'stats-live',
            next_appointment: {
                ...appointment,
                incident_id: 55,
                customer_name: 'Full Refresh Customer',
            },
        });

        expect(JSON.parse(pageRoot.getAttribute('data-next-appointment'))).toMatchObject({
            incident_id: 55,
            customer_name: 'Full Refresh Customer',
        });

        dashboard?.destroy();
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
