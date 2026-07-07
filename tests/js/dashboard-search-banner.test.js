import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { hideSearchBanner, resolveSearchBannerMessage, showSearchBanner } from '../../resources/js/dashboard-search-banner';

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

    it('shows result query message for matches', () => {
        const card = document.querySelector('.dashboard-service-cases-card');

        showSearchBanner(card, { matchCount: 1, query: 'RD3437143' });

        const banner = document.querySelector('[data-dashboard-search-banner]');
        expect(banner?.hidden).toBe(false);
        expect(banner?.querySelector('[data-dashboard-search-banner-title]')?.classList.contains('d-none')).toBe(false);
        expect(banner?.querySelector('[data-dashboard-search-banner-message]')?.textContent)
            .toBe('Showing results for RD3437143');
    });

    it('shows zero-result message without title', () => {
        const card = document.querySelector('.dashboard-service-cases-card');

        showSearchBanner(card, { matchCount: 0, query: 'RD3437143' });

        const banner = document.querySelector('[data-dashboard-search-banner]');
        expect(banner?.hidden).toBe(false);
        expect(banner?.querySelector('[data-dashboard-search-banner-title]')?.classList.contains('d-none')).toBe(true);
        expect(banner?.querySelector('[data-dashboard-search-banner-message]')?.textContent)
            .toBe('No record found for RD3437143');
    });

    it('shows legacy intake banner message without duplicate no-record text', () => {
        const card = document.querySelector('.dashboard-service-cases-card');

        showSearchBanner(card, {
            matchCount: 0,
            query: 'RD3395988',
            intake: {
                classification: 'legacy',
                requires_confirmation: true,
                legacy_preview: { order_id: 'RD3395988' },
            },
        });

        expect(document.querySelector('[data-dashboard-search-banner-message]')?.textContent)
            .toBe('Legacy order found — create service request');
    });

    it('shows new contact intake banner message', () => {
        const card = document.querySelector('.dashboard-service-cases-card');

        showSearchBanner(card, {
            matchCount: 0,
            query: 'Unknown Customer Name',
            intake: {
                classification: 'new_contact',
            },
        });

        expect(resolveSearchBannerMessage({
            matchCount: 0,
            query: 'Unknown Customer Name',
            intake: { classification: 'new_contact' },
        })).toBe('No existing record — create new service request');
    });

    it('shows error message for failed search', () => {
        const card = document.querySelector('.dashboard-service-cases-card');

        showSearchBanner(card, { error: 'Unable to load search results. Please try again.' });

        const banner = document.querySelector('[data-dashboard-search-banner]');
        expect(banner?.hidden).toBe(false);
        expect(banner?.classList.contains('dashboard-search-banner--error')).toBe(true);
        expect(banner?.querySelector('[data-dashboard-search-banner-message]')?.textContent)
            .toBe('Unable to load search results. Please try again.');
    });

    it('hides banner when search is cleared', () => {
        const card = document.querySelector('.dashboard-service-cases-card');

        showSearchBanner(card, { matchCount: 2, query: 'RD-MULTI' });
        hideSearchBanner(card);

        const banner = document.querySelector('[data-dashboard-search-banner]');
        expect(banner?.hidden).toBe(true);
        expect(banner?.classList.contains('d-none')).toBe(true);
        expect(banner?.classList.contains('dashboard-search-banner--error')).toBe(false);
    });
});
