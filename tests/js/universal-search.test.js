import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    detectSearchIntent,
    initUniversalSearch,
    isUniversalSearchActive,
    resetUniversalSearchState,
    SEARCH_INTENT,
    shouldRunUniversalSearch,
} from '../../resources/js/universal-search';

vi.mock('../../resources/js/live-dashboard', () => ({
    applyRows: vi.fn(),
}));

import { applyRows } from '../../resources/js/live-dashboard';

describe('detectSearchIntent', () => {
    it.each([
        ['9876543210', SEARCH_INTENT.STRUCTURED],
        ['RD3434509', SEARCH_INTENT.STRUCTURED],
        ['Danzo', SEARCH_INTENT.TEXT],
    ])('classifies %s', (query, expectedIntent) => {
        expect(detectSearchIntent(query)).toBe(expectedIntent);
    });
});

describe('shouldRunUniversalSearch', () => {
    it.each([
        ['9', true],
        ['D', false],
        ['Da', true],
    ])('returns %s -> %s', (query, expected) => {
        expect(shouldRunUniversalSearch(query)).toBe(expected);
    });
});

describe('initUniversalSearch', () => {
    beforeEach(() => {
        resetUniversalSearchState();
        vi.useFakeTimers();
        applyRows.mockReset();
        global.fetch = vi.fn();
    });

    afterEach(() => {
        resetUniversalSearchState();
        document.body.innerHTML = '';
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('redirects to dashboard with query when not on dashboard page', () => {
        document.body.innerHTML = `
            <form data-universal-search-form>
                <input id="global-search-input" type="search" value="9876543210">
            </form>
        `;

        Object.defineProperty(window, 'location', {
            configurable: true,
            writable: true,
            value: new URL('http://localhost/orders'),
        });

        initUniversalSearch();

        document.querySelector('[data-universal-search-form]')?.dispatchEvent(
            new Event('submit', { bubbles: true, cancelable: true }),
        );

        expect(window.location.href).toBe('http://localhost/dashboard?q=9876543210');
    });

    it('runs server search on dashboard submit', async () => {
        Object.defineProperty(window, 'location', {
            configurable: true,
            writable: true,
            value: new URL('http://localhost/dashboard'),
        });

        document.body.innerHTML = `
            <div id="dashboard-page" data-search-url="/dashboard/search" data-live-filter="all"></div>
            <form data-universal-search-form>
                <span data-universal-search-control>
                    <span data-universal-search-icon><i class="bi bi-search"></i></span>
                </span>
                <input id="global-search-input" type="search" value="RD3434509">
            </form>
        `;

        global.fetch.mockResolvedValue({
            ok: true,
            json: async () => ({
                match_count: 1,
                rows: [{
                    incident_id: 99,
                    html: '<tr id="service-case-row-99" data-incident-id="99"><td>ORD-100</td></tr>',
                }],
            }),
        });

        initUniversalSearch({ pageRoot: document.getElementById('dashboard-page') });

        document.querySelector('[data-universal-search-form]')?.dispatchEvent(
            new Event('submit', { bubbles: true, cancelable: true }),
        );

        await Promise.resolve();
        await Promise.resolve();

        expect(global.fetch).toHaveBeenCalledWith(
            expect.stringContaining('/dashboard/search?q=RD3434509'),
            expect.any(Object),
        );
        expect(applyRows).toHaveBeenCalledTimes(1);
        expect(isUniversalSearchActive()).toBe(true);
    });

    it('runs server search on Enter even for a single-character query', async () => {
        Object.defineProperty(window, 'location', {
            configurable: true,
            writable: true,
            value: new URL('http://localhost/dashboard'),
        });

        document.body.innerHTML = `
            <div id="dashboard-page" data-search-url="/dashboard/search" data-live-filter="all"></div>
            <form data-universal-search-form>
                <input id="global-search-input" type="search" value="D">
            </form>
        `;

        global.fetch.mockResolvedValue({
            ok: true,
            json: async () => ({
                match_count: 0,
                rows: [],
            }),
        });

        initUniversalSearch({ pageRoot: document.getElementById('dashboard-page') });

        document.getElementById('global-search-input')?.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }),
        );

        await Promise.resolve();

        expect(global.fetch).toHaveBeenCalledWith(
            expect.stringContaining('/dashboard/search?q=D'),
            expect.any(Object),
        );
    });

    it('bootstraps server search from dashboard URL q param on init', async () => {
        Object.defineProperty(window, 'location', {
            configurable: true,
            writable: true,
            value: new URL('http://localhost/dashboard?q=9883534'),
        });

        document.body.innerHTML = `
            <div id="dashboard-page" data-search-url="/dashboard/search" data-live-filter="all"></div>
            <input id="global-search-input" type="search" value="">
        `;

        global.fetch.mockResolvedValue({
            ok: true,
            json: async () => ({
                match_count: 1,
                rows: [{
                    incident_id: 1,
                    html: '<tr id="service-case-row-1" data-incident-id="1"><td>Row 1</td></tr>',
                }],
            }),
        });

        initUniversalSearch({ pageRoot: document.getElementById('dashboard-page') });

        await vi.runAllTimersAsync();

        expect(document.getElementById('global-search-input').value).toBe('9883534');
        expect(global.fetch).toHaveBeenCalledWith(
            expect.stringContaining('/dashboard/search?q=9883534'),
            expect.any(Object),
        );
        expect(applyRows).toHaveBeenCalledTimes(1);
    });
});
