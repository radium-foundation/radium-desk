import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    ensureViewOnlyMetricFresh,
    initViewOnlyMetricRefresh,
    isViewOnlyMetricStale,
    refreshViewOnlyMetric,
    resetViewOnlyMetricStateForTests,
    seedViewOnlyMetricFromDom,
} from '../../resources/js/dashboard-live-counts';

const buildPage = () => {
    document.body.innerHTML = `
        <div id="dashboard-page"
             data-live-counts-url="/dashboard/live/counts"
             data-live-interval-idle="120">
            <a href="/dashboard?workspace=active_cases"
               data-dashboard-metric="active_cases"
               data-view-only-metric="1"
               class="dashboard-kpi-item">
                <div class="dashboard-kpi-content">
                    <div class="dashboard-kpi-value">100</div>
                    <button type="button"
                            data-dashboard-metric-refresh="active_cases"
                            class="dashboard-kpi-refresh">↻</button>
                    <div data-dashboard-metric-updated="active_cases"></div>
                </div>
            </a>
        </div>
    `;

    return document.getElementById('dashboard-page');
};

describe('dashboard view-only metric refresh', () => {
    beforeEach(() => {
        resetViewOnlyMetricStateForTests();
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-08-10T12:00:00+05:30'));
    });

    afterEach(() => {
        resetViewOnlyMetricStateForTests();
        vi.useRealTimers();
        vi.unstubAllGlobals();
        vi.clearAllMocks();
        document.body.innerHTML = '';
    });

    it('fetches once when stale and skips when fresh', async () => {
        const pageRoot = buildPage();

        const fetchMock = vi.fn(async () => ({
            ok: true,
            json: async () => ({ metric: 'active_cases', count: 200 }),
        }));
        vi.stubGlobal('fetch', fetchMock);

        await ensureViewOnlyMetricFresh(pageRoot, 'active_cases');
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock).toHaveBeenCalledWith(
            '/dashboard/live/counts?metric=active_cases',
            expect.objectContaining({ credentials: 'same-origin' }),
        );
        expect(pageRoot.querySelector('.dashboard-kpi-value')?.textContent).toBe('200');

        fetchMock.mockClear();
        await ensureViewOnlyMetricFresh(pageRoot, 'active_cases');
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('treats metric as stale after idle freshness window', async () => {
        const pageRoot = buildPage();
        seedViewOnlyMetricFromDom(pageRoot, 'active_cases');

        vi.advanceTimersByTime(121_000);
        expect(isViewOnlyMetricStale(pageRoot, 'active_cases')).toBe(true);
    });

    it('manual refresh button performs one lightweight fetch', async () => {
        const pageRoot = buildPage();
        initViewOnlyMetricRefresh(pageRoot);

        const fetchMock = vi.fn(async () => ({
            ok: true,
            json: async () => ({ metric: 'active_cases', count: 305 }),
        }));
        vi.stubGlobal('fetch', fetchMock);

        pageRoot.querySelector('[data-dashboard-metric-refresh="active_cases"]')
            ?.dispatchEvent(new MouseEvent('click', { bubbles: true }));

        await vi.waitFor(async () => {
            expect(pageRoot.querySelector('.dashboard-kpi-value')?.textContent).toBe('305');
        });

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(String(fetchMock.mock.calls[0][0])).toContain('/dashboard/live/counts');
        expect(String(fetchMock.mock.calls[0][0])).not.toContain('/dashboard/live?');
    });

    it('prevents duplicate in-flight refresh requests', async () => {
        const pageRoot = buildPage();
        let resolveFetch;
        const fetchMock = vi.fn(() => new Promise((resolve) => {
            resolveFetch = resolve;
        }));
        vi.stubGlobal('fetch', fetchMock);

        const first = refreshViewOnlyMetric(pageRoot, 'active_cases', { force: true });
        const second = refreshViewOnlyMetric(pageRoot, 'active_cases', { force: true });

        expect(fetchMock).toHaveBeenCalledTimes(1);

        resolveFetch({
            ok: true,
            json: async () => ({ metric: 'active_cases', count: 42 }),
        });

        await Promise.all([first, second]);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('preserves existing value on failure and shows error state', async () => {
        const pageRoot = buildPage();
        seedViewOnlyMetricFromDom(pageRoot, 'active_cases');

        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 500 })));

        await refreshViewOnlyMetric(pageRoot, 'active_cases', { force: true });

        expect(pageRoot.querySelector('.dashboard-kpi-value')?.textContent).toBe('100');
        expect(pageRoot.querySelector('.dashboard-kpi-item')?.classList.contains('is-metric-error')).toBe(true);
        expect(pageRoot.querySelector('[data-dashboard-metric-updated="active_cases"]')?.textContent)
            .toBe('Update failed');
    });

    it('shows loading state without clearing the displayed value', async () => {
        const pageRoot = buildPage();
        seedViewOnlyMetricFromDom(pageRoot, 'active_cases');

        let resolveFetch;
        vi.stubGlobal('fetch', vi.fn(() => new Promise((resolve) => {
            resolveFetch = resolve;
        })));

        const pending = refreshViewOnlyMetric(pageRoot, 'active_cases', { force: true });

        expect(pageRoot.querySelector('.dashboard-kpi-item')?.classList.contains('is-metric-refreshing')).toBe(true);
        expect(pageRoot.querySelector('.dashboard-kpi-value')?.textContent).toBe('100');

        resolveFetch({
            ok: true,
            json: async () => ({ metric: 'active_cases', count: 150 }),
        });

        await pending;
        expect(pageRoot.querySelector('.dashboard-kpi-item')?.classList.contains('is-metric-refreshing')).toBe(false);
    });
});
