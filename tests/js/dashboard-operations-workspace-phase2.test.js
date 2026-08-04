import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    buildWorkspaceHistoryUrl,
    initOperationsWorkspaceSoftSwitch,
    isEmbeddedWorkspace,
    parseDashboardNavigationTarget,
    parseLegacyEmbeddedNavigationTarget,
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

const buildPage = ({ legacyKpis = false } = {}) => {
    const activeHref = legacyKpis
        ? '/incidents?status=active'
        : '/dashboard?workspace=active_cases';
    const refundHref = legacyKpis
        ? '/refunds?status=pending'
        : '/dashboard?workspace=refunds&status=pending';
    const softAttrs = legacyKpis
        ? ''
        : 'data-workspace="active_cases" data-operations-workspace-link';
    const refundSoftAttrs = legacyKpis
        ? ''
        : 'data-workspace="refunds" data-operations-workspace-link';

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
            <div id="dashboard-kpi-strip" class="dashboard-kpi-strip-host">
                <a href="${activeHref}"
                   ${softAttrs}
                   class="dashboard-kpi-item">Total Active Cases</a>
                <a href="${refundHref}"
                   ${refundSoftAttrs}
                   class="dashboard-kpi-item">Refunds</a>
            </div>
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
        </div>
    `;

    return document.getElementById('dashboard-page');
};

describe('operations workspace phase 2 embed', () => {
    afterEach(() => {
        document.body.innerHTML = '';
        vi.clearAllMocks();
        vi.unstubAllGlobals();
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

    it('maps legacy kpi urls to embedded workspaces', () => {
        expect(parseLegacyEmbeddedNavigationTarget('/incidents?status=active')).toMatchObject({
            workspace: 'active_cases',
            kind: 'embedded',
        });
        expect(parseLegacyEmbeddedNavigationTarget('/refunds?status=pending')).toMatchObject({
            workspace: 'refunds',
            kind: 'embedded',
        });
        expect(parseLegacyEmbeddedNavigationTarget('/incidents')).toBeNull();
        expect(parseDashboardNavigationTarget('/incidents?status=active')).toBeNull();
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

        await switchOperationsWorkspace(pageRoot, {
            workspace: 'active_cases',
            kind: 'embedded',
            operationQueue: 'action_required',
            serviceCaseFilter: 'action_required',
            url: new URL('/dashboard?workspace=active_cases', window.location.origin),
            query: { workspace: 'active_cases' },
        });

        expect(fetch).toHaveBeenCalledWith(
            expect.stringContaining('/dashboard/workspace?workspace=active_cases'),
            expect.objectContaining({ credentials: 'same-origin' }),
        );
        expect(pageRoot.querySelector('[data-operations-embedded-panel="active_cases"]')).not.toBeNull();
        expect(pageRoot.querySelector('[data-operations-case-host]')?.hidden).toBe(true);
        expect(stopPolling).toHaveBeenCalled();
        expect(pageRoot.dataset.operationsEmbeddedActive).toBe('1');
        expect(pageRoot.dataset.liveUpdatesEnabled).toBe('0');
    });

    it('intercepts stale legacy kpi hrefs when phase 2 embed is enabled', async () => {
        const pageRoot = buildPage({ legacyKpis: true });
        initOperationsWorkspaceSoftSwitch({ pageRoot });

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

        const link = pageRoot.querySelector('a.dashboard-kpi-item');
        link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));

        await vi.waitFor(() => {
            expect(pageRoot.querySelector('[data-operations-embedded-panel="active_cases"]')).not.toBeNull();
        });

        expect(fetch).toHaveBeenCalledWith(
            expect.stringContaining('/dashboard/workspace?workspace=active_cases'),
            expect.any(Object),
        );
    });

    it('falls back to dashboard workspace url not legacy listings when embed fetch fails', async () => {
        const pageRoot = buildPage();
        const assign = vi.fn();
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, json: async () => ({}) })));
        vi.spyOn(window.location, 'assign').mockImplementation(assign);

        await switchOperationsWorkspace(pageRoot, {
            workspace: 'active_cases',
            kind: 'embedded',
            operationQueue: 'action_required',
            serviceCaseFilter: 'action_required',
            url: new URL('/dashboard?workspace=active_cases', 'http://localhost'),
            query: { workspace: 'active_cases' },
        });

        expect(assign).toHaveBeenCalledWith(expect.stringContaining('/dashboard?workspace=active_cases'));
        expect(assign.mock.calls.flat().join(' ')).not.toContain('/incidents');
    });

    it('resumes case host when returning from embedded workspace', async () => {
        const pageRoot = buildPage();
        pageRoot.dataset.operationsWorkspaceKind = 'embedded';
        pageRoot.dataset.operationsEmbeddedActive = '1';
        pageRoot.dataset.liveUpdatesPausedForEmbed = '1';
        pageRoot.dataset.liveUpdatesEnabled = '0';
        pageRoot.querySelector('[data-operations-case-host]').hidden = true;
        pageRoot.querySelector('[data-operations-embedded-host]').hidden = false;
        pageRoot.querySelector('[data-operations-embedded-host]').innerHTML = '<div>embed</div>';

        await switchOperationsWorkspace(pageRoot, {
            workspace: 'attention',
            kind: 'case_queue',
            operationQueue: 'attention',
            serviceCaseFilter: 'attention',
            url: new URL('/dashboard?workspace=attention', 'http://localhost'),
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
