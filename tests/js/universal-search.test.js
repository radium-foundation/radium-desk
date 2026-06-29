import { afterEach, describe, expect, it, vi } from 'vitest';
import { initUniversalSearch } from '../../resources/js/universal-search';

describe('initUniversalSearch', () => {
    afterEach(() => {
        document.body.innerHTML = '';
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

    it('delegates to dashboard quick filter when on dashboard page', () => {
        Object.defineProperty(window, 'location', {
            configurable: true,
            writable: true,
            value: new URL('http://localhost/dashboard'),
        });

        document.body.innerHTML = `
            <div id="dashboard-page"></div>
            <form data-universal-search-form>
                <input id="global-search-input" type="search" value="RD3434509">
            </form>
        `;

        const setQuery = vi.fn();
        initUniversalSearch({
            getDashboardQuickFilter: () => ({ setQuery }),
        });

        document.querySelector('[data-universal-search-form]')?.dispatchEvent(
            new Event('submit', { bubbles: true, cancelable: true }),
        );

        expect(setQuery).toHaveBeenCalledWith('RD3434509', { runSearch: true });
    });
});
