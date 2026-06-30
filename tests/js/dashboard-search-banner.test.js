import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { hideSearchBanner, showSearchBanner } from '../../resources/js/dashboard-search-banner';

describe('dashboard search banner', () => {
    beforeEach(() => {
        document.body.innerHTML = `
            <div class="dashboard-service-cases-card">
                <div class="dashboard-search-banner d-none"
                     data-dashboard-search-banner
                     hidden>
                    <strong class="d-none" data-dashboard-search-banner-title>Search Results</strong>
                    <p data-dashboard-search-banner-message></p>
                </div>
            </div>
        `;
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('shows result count message for matches', () => {
        const card = document.querySelector('.dashboard-service-cases-card');

        showSearchBanner(card, { matchCount: 1 });

        const banner = document.querySelector('[data-dashboard-search-banner]');
        expect(banner?.hidden).toBe(false);
        expect(banner?.querySelector('[data-dashboard-search-banner-message]')?.textContent)
            .toBe('Showing 1 matching service case');
    });

    it('shows zero-result message without title', () => {
        const card = document.querySelector('.dashboard-service-cases-card');

        showSearchBanner(card, { matchCount: 0 });

        const banner = document.querySelector('[data-dashboard-search-banner]');
        expect(banner?.hidden).toBe(false);
        expect(banner?.querySelector('[data-dashboard-search-banner-title]')?.classList.contains('d-none')).toBe(true);
        expect(banner?.querySelector('[data-dashboard-search-banner-message]')?.textContent)
            .toBe('No matching service cases found.');
    });

    it('hides banner when search is cleared', () => {
        const card = document.querySelector('.dashboard-service-cases-card');

        showSearchBanner(card, { matchCount: 2 });
        hideSearchBanner(card);

        const banner = document.querySelector('[data-dashboard-search-banner]');
        expect(banner?.hidden).toBe(true);
        expect(banner?.classList.contains('d-none')).toBe(true);
    });
});
