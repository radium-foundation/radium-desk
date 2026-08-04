import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    applyWorkspaceChrome,
    buildWorkspaceHistoryUrl,
    parseDashboardNavigationTarget,
    switchOperationsWorkspace,
} from '../../resources/js/dashboard-operations-workspace';

vi.mock('../../resources/js/live-dashboard', () => ({
    refreshDashboard: vi.fn(async () => ({
        workspace: 'attention',
        operation_queue: 'attention',
        service_case_filter: 'attention',
        panel_title: 'Exceptions',
        live_scope: 'operations_scope',
        rows: [],
        loaded_count: 0,
        total_count: 0,
    })),
}));

import { refreshDashboard } from '../../resources/js/live-dashboard';

const buildPage = ({
    workspace = 'action_required',
    queue = 'action_required',
    filter = 'action_required',
} = {}) => {
    document.body.innerHTML = `
        <div id="dashboard-page"
             data-live-url="/dashboard/live"
             data-live-workspace="${workspace}"
             data-live-queue="${queue}"
             data-live-filter="${filter}"
             data-operations-workspace-soft-switch="1">
            <div id="dashboard-kpi-strip">
                <a href="/dashboard?queue=action_required#dashboard-service-cases-panel"
                   data-workspace="action_required"
                   data-operations-workspace-link
                   class="dashboard-kpi-item">Open</a>
                <a href="/dashboard?filter=overdue#dashboard-service-cases-panel"
                   data-workspace="overdue"
                   data-operations-workspace-link
                   class="dashboard-kpi-item">Overdue</a>
                <a href="/incidents?status=active" class="dashboard-kpi-item">Total Active Cases</a>
            </div>
            <div class="dashboard-primary-panel">
                <div class="dashboard-service-cases-card"
                     id="dashboard-service-cases-panel"
                     data-operation-queue="${queue}"
                     data-service-case-filter="${filter}"
                     data-service-cases-loaded="35"
                     data-service-case-filter-total="35">
                    <h2 class="dashboard-cases-title">Ready Queue</h2>
                    <a href="/dashboard?queue=action_required"
                       data-workspace="action_required"
                       data-operations-workspace-link
                       class="dashboard-case-filter-chip is-active"
                       role="tab">
                        <span class="dashboard-case-filter-chip__label">Ready Queue</span>
                    </a>
                    <a href="/dashboard?queue=attention"
                       data-workspace="attention"
                       data-operations-workspace-link
                       class="dashboard-case-filter-chip"
                       role="tab">
                        <span class="dashboard-case-filter-chip__label">Exceptions</span>
                    </a>
                    <div id="dashboard-service-cases-scroll">
                        <div class="dashboard-workspace-skeleton d-none" data-operations-workspace-skeleton></div>
                        <table><tbody id="dashboard-service-cases-body"></tbody></table>
                    </div>
                </div>
            </div>
        </div>
    `;

    return document.getElementById('dashboard-page');
};

describe('operations workspace soft switch helpers', () => {
    afterEach(() => {
        document.body.innerHTML = '';
        vi.clearAllMocks();
    });

    it('parses queue, filter, and workspace dashboard targets', () => {
        expect(parseDashboardNavigationTarget('/dashboard?queue=scheduled')).toMatchObject({
            workspace: 'scheduled',
            operationQueue: 'scheduled',
            serviceCaseFilter: 'scheduled',
        });

        expect(parseDashboardNavigationTarget('/dashboard?filter=overdue')).toMatchObject({
            workspace: 'overdue',
            serviceCaseFilter: 'overdue',
        });

        expect(parseDashboardNavigationTarget('/dashboard?workspace=waiting_customer')).toMatchObject({
            workspace: 'waiting_customer',
            operationQueue: 'waiting_customer',
        });

        expect(parseDashboardNavigationTarget('/incidents?status=active')).toBeNull();
        expect(parseDashboardNavigationTarget('/refunds?status=pending')).toBeNull();
    });

    it('builds history urls with workspace while clearing legacy nav params', () => {
        expect(buildWorkspaceHistoryUrl('attention', {
            pathname: '/dashboard',
            search: '?queue=action_required&filter=overdue&foo=1',
        })).toBe('/dashboard?workspace=attention&foo=1');
    });

    it('updates chrome datasets and active chip without touching the page shell', async () => {
        const pageRoot = buildPage();
        const pushState = vi.spyOn(window.history, 'pushState');

        await switchOperationsWorkspace(pageRoot, {
            workspace: 'attention',
            operationQueue: 'attention',
            serviceCaseFilter: 'attention',
            url: new URL('/dashboard?queue=attention', window.location.origin),
        });

        expect(refreshDashboard).toHaveBeenCalledWith(
            pageRoot,
            'operations_workspace_switch',
            { force: true, resetPagination: true },
        );
        expect(pageRoot.dataset.liveWorkspace).toBe('attention');
        expect(pageRoot.dataset.liveQueue).toBe('attention');
        expect(pageRoot.querySelector('.dashboard-cases-title')?.textContent).toBe('Exceptions');
        expect(pageRoot.querySelector('[data-workspace="attention"]')?.classList.contains('is-active')).toBe(true);
        expect(pageRoot.querySelector('[data-workspace="action_required"]')?.classList.contains('is-active')).toBe(false);
        expect(pushState).toHaveBeenCalled();
        expect(document.getElementById('dashboard-kpi-strip')).not.toBeNull();
    });

    it('applyWorkspaceChrome toggles scheduled board attributes', () => {
        const pageRoot = buildPage();

        applyWorkspaceChrome(pageRoot, {
            workspace: 'scheduled',
            operationQueue: 'scheduled',
            serviceCaseFilter: 'scheduled',
        });

        const card = pageRoot.querySelector('.dashboard-service-cases-card');
        expect(card?.getAttribute('data-scheduled-appointment-board')).toBe('true');
        expect(card?.dataset.operationsWidget).toBe('appointments');
    });
});
