import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { loadHealthDetail, loadLazyTab, showLazyLoadError } from '../../resources/js/operations-dashboard';

describe('operations-dashboard lazy loading', () => {
    beforeEach(() => {
        vi.stubGlobal('fetch', vi.fn());
    });

    afterEach(() => {
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
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
                        <div class="operations-lazy-placeholder card border-0 shadow-sm">
                            <div class="card-body py-4 text-center text-muted">
                                <div class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></div>
                                <span>Loading support intelligence…</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;

        return document.getElementById('operations-dashboard-root');
    };

    const mountHealthDetail = () => {
        document.body.innerHTML = `
            <div id="operations-dashboard-root" data-live-url="/admin/operations/live">
                <div
                    id="operations-health-radiumbox"
                    class="accordion-collapse collapse show"
                    data-operations-lazy-section="radiumbox_health"
                    data-operations-lazy-loaded="false"
                >
                    <div class="accordion-body pt-0" id="operations-health-detail-radiumbox">
                        <div class="operations-lazy-placeholder card border-0 shadow-sm">
                            <div class="card-body py-4 text-center text-muted">
                                <div class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></div>
                                <span>Loading RadiumBox details…</span>
                            </div>
                        </div>
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
        expect(content.textContent).toContain('Missing radiumbox_health content');
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
