import { refreshDashboard } from './live-dashboard';

/**
 * Hardware workspace SSR omits service-case KPIs; load authoritative strip via kpis_only.
 */
export const initHardwareDashboardKpiStrip = (pageRoot = document) => {
    if (pageRoot.dataset.hardwareKpiRefresh !== '1') {
        return;
    }

    void refreshDashboard(pageRoot, 'hardware-kpi-initial', { kpisOnly: true });
};