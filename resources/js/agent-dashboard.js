const LAST_CUSTOMER_STORAGE_KEY = 'radium.agent.lastCustomer';
const RECENT_CUSTOMERS_STORAGE_KEY = 'radium.agent.recentCustomers';
const MAX_RECENT_CUSTOMERS = 3;
const NOTIFICATION_PERMISSION_KEY = 'radium.agent.appointmentNotifications.permissionRequested';
const NOTIFICATION_SENT_PREFIX = 'radium.agent.appointmentNotifications.sent.';
const BANNER_DISMISSED_PREFIX = 'radium.agent.appointmentBanner.dismissed.';

const REMINDER_THRESHOLDS = [30, 10, 0];

export const CUSTOMER360_APPOINTMENT_TAB = 'overview';
export const CUSTOMER360_APPOINTMENT_ANCHOR = 'support-appointments';

const normalizeRecentCustomer = (entry) => {
    if (!entry?.incidentId) {
        return null;
    }

    return {
        incidentId: String(entry.incidentId),
        referenceLabel: entry.referenceLabel ?? entry.customerName ?? 'Customer',
        openedAt: entry.openedAt ?? null,
    };
};

const readRecentCustomers = () => {
    try {
        const raw = localStorage.getItem(RECENT_CUSTOMERS_STORAGE_KEY);

        if (raw) {
            const parsed = JSON.parse(raw);

            if (Array.isArray(parsed)) {
                return parsed
                    .map(normalizeRecentCustomer)
                    .filter(Boolean)
                    .slice(0, MAX_RECENT_CUSTOMERS);
            }
        }

        const legacyRaw = localStorage.getItem(LAST_CUSTOMER_STORAGE_KEY);

        if (!legacyRaw) {
            return [];
        }

        const legacyEntry = normalizeRecentCustomer(JSON.parse(legacyRaw));

        return legacyEntry ? [legacyEntry] : [];
    } catch {
        return [];
    }
};

export const rememberLastCustomer = (incidentId, referenceLabel = '') => {
    if (!incidentId) {
        return;
    }

    const entry = {
        incidentId: String(incidentId),
        referenceLabel: referenceLabel || 'Customer',
        openedAt: new Date().toISOString(),
    };

    const recentCustomers = [
        entry,
        ...readRecentCustomers().filter((customer) => customer.incidentId !== entry.incidentId),
    ].slice(0, MAX_RECENT_CUSTOMERS);

    try {
        localStorage.setItem(RECENT_CUSTOMERS_STORAGE_KEY, JSON.stringify(recentCustomers));
        localStorage.setItem(LAST_CUSTOMER_STORAGE_KEY, JSON.stringify({
            incidentId: entry.incidentId,
            customerName: entry.referenceLabel,
            openedAt: entry.openedAt,
        }));
    } catch {
        // Ignore unavailable storage in test or private browsing contexts.
    }
};

const isBannerDismissed = (incidentId) => (
    localStorage.getItem(`${BANNER_DISMISSED_PREFIX}${incidentId}`) === '1'
);

const dismissAppointmentBanner = (pageRoot, incidentId) => {
    if (!incidentId) {
        return;
    }

    localStorage.setItem(`${BANNER_DISMISSED_PREFIX}${incidentId}`, '1');

    pageRoot?.querySelectorAll('[data-agent-appointment-sticky]').forEach((host) => {
        if (host.getAttribute('data-incident-id') === String(incidentId)) {
            host.classList.add('is-dismissed');
        }
    });
};

const syncStickyBannerVisibility = (pageRoot, appointment) => {
    const stickyHost = pageRoot?.querySelector('[data-agent-appointment-sticky]');

    if (!stickyHost || !appointment) {
        return;
    }

    const incidentId = String(appointment.incidentId);

    if (stickyHost.getAttribute('data-incident-id') !== incidentId) {
        return;
    }

    stickyHost.classList.toggle('is-dismissed', isBannerDismissed(incidentId));
};

const openCustomer360 = (pageRoot, incidentId, customerName = '', { forAppointment = false } = {}) => {
    const detail = {
        incidentId: String(incidentId),
        referenceLabel: customerName,
    };

    if (forAppointment) {
        detail.tab = CUSTOMER360_APPOINTMENT_TAB;
        detail.anchor = CUSTOMER360_APPOINTMENT_ANCHOR;
    }

    document.dispatchEvent(new CustomEvent('customer360:open', { detail }));
};

const openCustomer360ForAppointment = (pageRoot, incidentId, customerName = '') => {
    openCustomer360(pageRoot, incidentId, customerName, { forAppointment: true });
};

const isDocumentVisible = () => document.visibilityState === 'visible';

const notificationSentKey = (incidentId, threshold) => `${NOTIFICATION_SENT_PREFIX}${incidentId}.${threshold}`;

const hasNotificationBeenSent = (incidentId, threshold) => (
    localStorage.getItem(notificationSentKey(incidentId, threshold)) === '1'
);

const markNotificationSent = (incidentId, threshold) => {
    localStorage.setItem(notificationSentKey(incidentId, threshold), '1');
};

const clearNotificationSentFlags = (incidentId) => {
    REMINDER_THRESHOLDS.forEach((threshold) => {
        localStorage.removeItem(notificationSentKey(incidentId, threshold));
    });
};

const requestNotificationPermissionOnce = async () => {
    if (!('Notification' in window)) {
        return 'denied';
    }

    if (Notification.permission !== 'default') {
        return Notification.permission;
    }

    if (localStorage.getItem(NOTIFICATION_PERMISSION_KEY) === '1') {
        return Notification.permission;
    }

    localStorage.setItem(NOTIFICATION_PERMISSION_KEY, '1');

    try {
        return await Notification.requestPermission();
    } catch {
        return Notification.permission;
    }
};

const showAppointmentToast = (showToast, appointment, pageRoot) => {
    showToast?.({
        message: `Upcoming appointment with ${appointment.customerName} at ${appointment.timeLabel}`,
        variant: 'info',
        actions: [{
            label: 'Open Customer360',
            onClick: () => openCustomer360ForAppointment(pageRoot, appointment.incidentId, appointment.customerName),
        }],
    });
};

const showBrowserAppointmentNotification = (appointment, pageRoot) => {
    if (!('Notification' in window) || Notification.permission !== 'granted') {
        return false;
    }

    try {
        const notification = new Notification('Upcoming Appointment', {
            body: `Customer: ${appointment.customerName}\nTime: ${appointment.timeLabel}`,
            tag: `appointment-${appointment.incidentId}`,
        });

        notification.onclick = () => {
            window.focus();
            openCustomer360ForAppointment(pageRoot, appointment.incidentId, appointment.customerName);
            notification.close();
        };

        return true;
    } catch {
        return false;
    }
};

const parseAppointment = (pageRoot) => {
    const raw = pageRoot?.dataset.nextAppointment;

    if (!raw) {
        return null;
    }

    try {
        const appointment = JSON.parse(raw);

        if (!appointment?.incident_id || !appointment?.starts_at) {
            return null;
        }

        return {
            incidentId: String(appointment.incident_id),
            customerName: appointment.customer_name ?? 'Customer',
            deviceModel: appointment.device_model ?? null,
            startsAt: new Date(appointment.starts_at),
            timeLabel: appointment.time_label ?? '',
            startsInLabel: appointment.starts_in_label ?? '',
            isOverdue: Boolean(appointment.is_overdue),
            isImminent: Boolean(appointment.is_imminent),
        };
    } catch {
        return null;
    }
};

const minutesUntil = (startsAt, now = new Date()) => (
    Math.round((startsAt.getTime() - now.getTime()) / 60000)
);

const evaluateAppointmentReminders = (appointment, pageRoot, showToast) => {
    const minutes = minutesUntil(appointment.startsAt);

    REMINDER_THRESHOLDS.forEach((threshold) => {
        if (hasNotificationBeenSent(appointment.incidentId, threshold)) {
            return;
        }

        const shouldNotify = threshold === 0
            ? minutes <= 0 && minutes >= -5
            : minutes <= threshold && minutes > threshold - 2;

        if (!shouldNotify) {
            return;
        }

        markNotificationSent(appointment.incidentId, threshold);

        if (isDocumentVisible()) {
            showAppointmentToast(showToast, appointment, pageRoot);

            return;
        }

        if (!showBrowserAppointmentNotification(appointment, pageRoot)) {
            showAppointmentToast(showToast, appointment, pageRoot);
        }
    });
};

const bindRecentCustomers = (pageRoot, container) => {
    const recentCustomers = readRecentCustomers();

    if (recentCustomers.length === 0) {
        return;
    }

    const list = container.querySelector('[data-agent-recent-customers-list]');

    if (!list) {
        return;
    }

    container.hidden = false;
    container.classList.remove('d-none');
    list.replaceChildren();

    recentCustomers.forEach((customer, index) => {
        const chip = document.createElement('button');

        chip.type = 'button';
        chip.className = 'dashboard-recent-customers__chip dashboard-u-focus-ring';
        chip.textContent = customer.referenceLabel;

        if (index > 0) {
            chip.classList.add('dashboard-recent-customers__chip--compact');
        }

        chip.setAttribute(
            'aria-label',
            `Open ${customer.referenceLabel} in Customer360`,
        );

        chip.addEventListener('click', () => {
            openCustomer360(pageRoot, customer.incidentId, customer.referenceLabel);
        });

        list.appendChild(chip);
    });
};

const bindCustomer360Triggers = (pageRoot) => {
    pageRoot.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-agent-open-customer-360]');

        if (!trigger) {
            return;
        }

        event.preventDefault();

        const incidentId = trigger.getAttribute('data-agent-open-customer-360');
        const customerName = trigger.getAttribute('data-agent-customer-name') ?? '';
        const forAppointment = trigger.hasAttribute('data-agent-open-appointment');

        if (forAppointment) {
            openCustomer360ForAppointment(pageRoot, incidentId, customerName);

            return;
        }

        openCustomer360(pageRoot, incidentId, customerName);
    });
};

const bindAppointmentBannerDismissal = (pageRoot) => {
    document.addEventListener('customer360:open', (event) => {
        const incidentId = event.detail?.incidentId;

        if (!incidentId) {
            return;
        }

        const stickyHost = pageRoot.querySelector('[data-agent-appointment-sticky]');

        if (stickyHost?.getAttribute('data-incident-id') === String(incidentId)) {
            dismissAppointmentBanner(pageRoot, incidentId);
        }
    });
};

export const initAgentDashboard = ({ pageRoot, showToast } = {}) => {
    const root = pageRoot ?? document.getElementById('dashboard-page');

    if (!root) {
        return null;
    }

    let appointment = parseAppointment(root);
    let intervalId = null;

    const tick = () => {
        if (!appointment) {
            return;
        }

        evaluateAppointmentReminders(appointment, root, showToast);
        syncStickyBannerVisibility(root, appointment);
    };

    const startReminders = () => {
        if (!appointment) {
            return;
        }

        void requestNotificationPermissionOnce();
        tick();

        if (intervalId !== null) {
            window.clearInterval(intervalId);
        }

        intervalId = window.setInterval(tick, Number(root.dataset.agentReminderIntervalSeconds ?? 60) * 1000);
    };

    const updateNextAppointment = (nextAppointment) => {
        if (!nextAppointment) {
            root.removeAttribute('data-next-appointment');
            appointment = null;

            if (intervalId !== null) {
                window.clearInterval(intervalId);
                intervalId = null;
            }

            return;
        }

        root.dataset.nextAppointment = JSON.stringify(nextAppointment);
        appointment = parseAppointment(root);
        syncStickyBannerVisibility(root, appointment);
        startReminders();
    };

    bindCustomer360Triggers(root);
    bindAppointmentBannerDismissal(root);

    const recentCustomersHost = root.querySelector('[data-agent-recent-customers]');

    if (recentCustomersHost) {
        bindRecentCustomers(root, recentCustomersHost);
    }

    syncStickyBannerVisibility(root, appointment);
    startReminders();

    return {
        updateNextAppointment,
        destroy: () => {
            if (intervalId !== null) {
                window.clearInterval(intervalId);
            }

            if (appointment) {
                clearNotificationSentFlags(appointment.incidentId);
            }
        },
    };
};

export const applyLiveRefreshNextAppointment = (dashboard, detail) => {
    if (detail !== null && detail !== undefined && 'next_appointment' in detail) {
        dashboard?.updateNextAppointment?.(detail.next_appointment);
    }
};

export {
    dismissAppointmentBanner,
    openCustomer360,
    openCustomer360ForAppointment,
};
