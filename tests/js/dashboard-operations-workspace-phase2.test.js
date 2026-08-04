import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    buildWorkspaceHistoryUrl,
    isEmbeddedWorkspace,
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
    stopPolling: vi.fn(),
    startPolling: vi.fn(),
}));

import { refreshDashboard, startPolling, stopPolling } from '../../resources/js/live-dashboard';

const buildPage = () => {
    document.body.innerHTML = `
        <div id="dashboard-page"
             data-live-url="/dashboard/live"
             data-live-workspace="action_required"
             data-live-queue="action_required"
             data-live-filter="action_required"
             data-live-mode="poll"
             data-live-updates-enabled="1"
             data-operations-workspace-kind="case_queue"
             data-operations-workspace-soft-switch="1"
             data-operations-workspace-phase2-embed="1"
             data-operations-workspace-url="/dashboard/workspace">
            <div data-operations-primary-panel>
                <div data-operations-case-host>
                    <div class="dashboard-service-cases-card" id="dashboard-service-cases-panel"
                         data-service-cases-loaded="10" data-service-case-filter-total="10">
                        <h2 class="dashboard-cases-title">Ready Queue</h2>
                        <a data-workspace="action_required" data-operations-workspace-link
                           class="dashboard-case-filter-chip is-active" role="tab"
                           href="/dashboard?queue=action_required">
                            <span class="dashboard-case-filter-chip__label">Ready Queue</span>
                        </a>
                        <div data-operations-workspace-skeleton class="d-none"></div>
                    </div>
                </div>
                <div data-operations-embedded-host hidden></div>
            </div>
            <a href="/dashboard?workspace=active_cases"
               data-workspace="active_cases"
               data-operations-workspace-link
               class="dashboard-kpi-item">Total Active Cases</a>
            <a href="/dashboard?workspace=refunds&status=pending"
               data-workspace="refunds"
               data-operations-workspace-link
               class="dashboard-kpi-item">Refunds</a>
        </div>
    `;

    return document.getElementById('dashboard-page');
};

describe('operations workspace phase 2 embed', () => {
    afterEach(() => {
        document.body.innerHTML = '';
        vi.clearAllMocks();
    });

    it('recognizes embedded workspace ids', () => {
        expect(isEmbeddedWorkspace('active_cases')).toBe(true);
        expect(isEmbeddedWorkspace('refunds')).toBe(true);
        expect(isEmbeddedWorkspace('action_required')).toBe(false);
    });

    it('parses embedded dashboard workspace urls', () => {
        expect(parseDashboardNavigationTarget('/dashboard?workspace=active_cases')).toMatchObject({
            workspace: 'active_cases',
            kind: 'embedded',
        });
        expect(parseDashboardNavigationTarget('/dashboard?workspace=refunds&status=pending')).toMatchObject({
            workspace: 'refunds',
            kind: 'embedded',
        });
    });

    it('soft-switches to active cases via panel endpoint without full navigation', async () => {
        const pageRoot = buildPage();
        vi.stubGlobal('fetch', vi.fn(async () => ({
            ok: true,
            json: async () => ({
                workspace: 'active_cases',
                kind: 'embedded',
                panel_title: 'Active Service Cases',
                panel_html: '<div data-operations-embedded-panel="active_cases">Active listing</div>',
                supports_live: false,
            }),
        })));

        const pushState = vi.spyOn(window.history, 'pushState');

        await switchOperationsWorkspace(pageRoot, {
            workspace: 'active_cases',
            kind: 'embedded',
            operationQueue: 'action_required',
            serviceCaseFilter: 'action_required',
            url: new URL('/dashboard?workspace=active_cases', window.location.origin),
            query: { workspace: 'active_cases' },
        });

        expect(fetch).toHaveBeenCalled();
        expect(stopPolling).toHaveBeenCalled();
        expect(pageRoot.dataset.operationsEmbeddedActive).toBe('1');
        expect(pageRoot.dataset.liveUpdatesEnabled).toBe('0');
        expect(pageRoot.querySelector('[data-operations-case-host]')?.hidden).toBe(true);
        expect(pageRoot.querySelector('[data-operations-embedded-host]')?.hidden).toBe(false);
        expect(pageRoot.querySelector('[data-operations-embedded-panel="active_cases"]')).not.toBeNull();
        expect(pushState).toHaveBeenCalled();
        expect(refreshDashboard).not.toHaveBeenCalled();
    });

    it('restores case host and resumes polling when leaving embed', async () => {
        const pageRoot = buildPage();
        pageRoot.dataset.operationsWorkspaceKind = 'embedded';
        pageRoot.dataset.operationsEmbeddedActive = '1';
        pageRoot.dataset.liveUpdatesEnabled = '0';
        pageRoot.dataset.liveUpdatesPausedForEmbed = '1';
        pageRoot.querySelector('[data-operations-case-host]').hidden = true;
        pageRoot.querySelector('[data-operations-embedded-host]').hidden = false;
        pageRoot.querySelector('[data-operations-embedded-host]').innerHTML = '<div>Refunds</div>';

        await switchOperationsWorkspace(pageRoot, {
            workspace: 'attention',
            kind: 'case_queue',
            operationQueue: 'attention',
            serviceCaseFilter: 'attention',
            url: new URL('/dashboard?workspace=attention', window.location.origin),
        });

        expect(pageRoot.querySelector('[data-operations-case-host]')?.hidden).toBe(false);
        expect(pageRoot.querySelector('[data-operations-embedded-host]')?.innerHTML).toBe('');
        expect(startPolling).toHaveBeenCalled();
        expect(refreshDashboard).toHaveBeenCalled();
        expect(pageRoot.dataset.operationsEmbeddedActive).toBeUndefined();
    });

    it('builds history urls for embedded workspaces with filter params', () => {
        expect(buildWorkspaceHistoryUrl('refunds', {
            pathname: '/dashboard',
            extraParams: { status: 'pending' },
        })).toBe('/dashboard?workspace=refunds&status=pending');
    });
});
