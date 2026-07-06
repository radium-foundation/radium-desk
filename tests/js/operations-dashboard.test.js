import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    guardAgainstStaleLazyPlaceholders,
    loadHealthDetail,
    loadLazyTab,
    showLazyLoadError,
    validateSectionHtml,
} from '../../resources/js/operations-dashboard';

describe('operations-dashboard lazy loading', () => {
    beforeEach(() => {
        vi.stubGlobal('fetch', vi.fn());
    });

    afterEach(() => {
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
        vi.useRealTimers();
    });

    const mountTodayTab = () => {
        document.body.innerHTML = `
            <div id="operations-dashboard-root" data-live-url="/admin/operations/live">
                <div
                    id="operations-pane-today"
                    data-operations-lazy-group="today"
                    data-operations-lazy-loaded="false"
                >
                    <div id="operations-tab-today-content">
                        <div class="operations-lazy-placeholder operations-skeleton-loader card border-0 shadow-sm">
                            <div class="card-body py-3">
                                <span class="visually-hidden">Loading support intelligence…</span>
                                <div class="operations-skeleton-line operations-skeleton-line--title"></div>
                                <div class="operations-skeleton-line"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;

        return document.getElementById('operations-dashboard-root');
    };

    const mountHealthDetail = ({ expanded = true, loaded = false } = {}) => {
        document.body.innerHTML = `
            <div id="operations-dashboard-root" data-live-url="/admin/operations/live">
                <div
                    id="operations-health-radiumbox"
                    class="accordion-collapse collapse ${expanded ? 'show' : ''}"
                    data-operations-lazy-section="health_radiumbox"
                    data-operations-lazy-loaded="${loaded ? 'true' : 'false'}"
                >
                    <div class="accordion-body pt-0" id="operations-health-detail-radiumbox">
                        <p class="operations-health-collapsed-hint">Expand to load RadiumBox details.</p>
                    </div>
                </div>
            </div>
        `;

        return {
            pageRoot: document.getElementById('operations-dashboard-root'),
            collapseElement: document.getElementById('operations-health-radiumbox'),
        };
    };

    it('replaces today spinner with error UI when live section is missing', async () => {
        const pageRoot = mountTodayTab();

        fetch.mockResolvedValueOnce({
            ok: true,
            json: async () => ({ html: {} }),
        });

        await loadLazyTab(pageRoot, 'today', { force: true });

        const content = document.getElementById('operations-tab-today-content');

        expect(content.querySelector('.operations-lazy-placeholder')).toBeNull();
        expect(content.querySelector('.operations-lazy-error')).not.toBeNull();
        expect(content.textContent).toContain('Missing today_tab content');
    });

    it('replaces health detail spinner with error UI when live section is missing', async () => {
        const { pageRoot, collapseElement } = mountHealthDetail();

        fetch.mockResolvedValueOnce({
            ok: true,
            json: async () => ({ html: {} }),
        });

        await loadHealthDetail(pageRoot, collapseElement, { force: true });

        const content = document.getElementById('operations-health-detail-radiumbox');

        expect(content.querySelector('.operations-lazy-placeholder')).toBeNull();
        expect(content.querySelector('.operations-lazy-error')).not.toBeNull();
        expect(content.textContent).toContain('Missing health_radiumbox content');
    });

    it('does not fetch health details for expanded accordions until user expands', async () => {
        mountHealthDetail({ expanded: true, loaded: false });

        expect(fetch).not.toHaveBeenCalled();
    });

    it('loads health details when loadHealthDetail is invoked after expand', async () => {
        const { pageRoot, collapseElement } = mountHealthDetail({ expanded: false, loaded: false });

        fetch.mockResolvedValueOnce({
            ok: true,
            json: async () => ({
                html: {
                    health_radiumbox: '<section>RadiumBox Health details</section>',
                },
            }),
        });

        await loadHealthDetail(pageRoot, collapseElement, { force: true });

        expect(fetch).toHaveBeenCalledTimes(1);
        expect(document.getElementById('operations-health-detail-radiumbox').textContent)
            .toContain('RadiumBox Health details');
        expect(collapseElement.dataset.operationsLazyLoaded).toBe('true');
    });

    it('allows health_status shells that only mention nested lazy sections', () => {
        const shellHtml = `
            <section id="operations-health-status">
                <div data-operations-lazy-section="health_radiumbox"></div>
            </section>
        `;

        expect(() => validateSectionHtml('health_status', shellHtml)).not.toThrow();
    });

    it('rejects sections that are still placeholder markup', () => {
        const placeholderHtml = `
            <div class="operations-lazy-placeholder card border-0 shadow-sm">
                <div class="card-body">Loading…</div>
            </div>
        `;

        expect(() => validateSectionHtml('today_tab', placeholderHtml))
            .toThrow('today_tab content is still loading.');
    });

    it('replaces stale lazy placeholders with timeout fallback UI', async () => {
        vi.useFakeTimers();

        const pageRoot = mountTodayTab();

        guardAgainstStaleLazyPlaceholders(pageRoot);

        await vi.advanceTimersByTimeAsync(30000);

        const content = document.getElementById('operations-tab-today-content');

        expect(content.querySelector('.operations-lazy-placeholder')).toBeNull();
        expect(content.querySelector('.operations-lazy-error')).not.toBeNull();
        expect(content.textContent).toContain('This section took too long to load.');
    });

    it('showLazyLoadError replaces placeholder content', () => {
        document.body.innerHTML = `
            <div id="operations-tab-today-content">
                <div class="operations-lazy-placeholder">
                    <span>Loading support intelligence…</span>
                </div>
            </div>
        `;

        showLazyLoadError('operations-tab-today-content', 'Failed to load', () => {});

        const content = document.getElementById('operations-tab-today-content');

        expect(content.querySelector('.operations-lazy-placeholder')).toBeNull();
        expect(content.querySelector('.operations-lazy-error')).not.toBeNull();
        expect(content.textContent).toContain('Failed to load');
    });
});
