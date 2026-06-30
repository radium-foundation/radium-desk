import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { initUniversalSearch } from '../../resources/js/universal-search';

describe('initUniversalSearch', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        global.fetch = vi.fn();
    });

    afterEach(() => {
        document.body.innerHTML = '';
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    const mountSearch = () => {
        document.body.innerHTML = `
            <form data-universal-search-form data-search-url="/search">
                <span data-universal-search-control>
                    <span data-universal-search-icon><i class="bi bi-search"></i></span>
                </span>
                <input id="global-search-input" type="search" value="">
                <div id="global-search-results" class="global-search-results d-none"></div>
            </form>
        `;

        initUniversalSearch();
    };

    it('runs search on form submit', async () => {
        mountSearch();

        document.getElementById('global-search-input').value = 'RD3434509';

        global.fetch.mockResolvedValue({
            ok: true,
            json: async () => ({
                match_count: 1,
                results: [{
                    incident_id: 99,
                    url: '/incidents/99',
                    service_case: 'SC00099',
                    reference_number: 'SC-00099',
                    order_id: 'RD3434509',
                    customer: 'Customer',
                    phone: '9876543210',
                    assigned_to: 'Agent',
                    status: 'Open',
                    age: '1d',
                }],
            }),
        });

        document.querySelector('[data-universal-search-form]')?.dispatchEvent(
            new Event('submit', { bubbles: true, cancelable: true }),
        );

        await Promise.resolve();
        await Promise.resolve();

        expect(global.fetch).toHaveBeenCalledTimes(1);
        expect(global.fetch).toHaveBeenCalledWith(
            '/search?q=RD3434509',
            expect.objectContaining({
                headers: expect.objectContaining({
                    Accept: 'application/json',
                }),
            }),
        );

        const resultsPanel = document.getElementById('global-search-results');
        expect(resultsPanel.classList.contains('d-none')).toBe(false);
        expect(resultsPanel.innerHTML).toContain('RD3434509');
    });

    it('runs search on Enter', async () => {
        mountSearch();

        document.getElementById('global-search-input').value = 'D';

        global.fetch.mockResolvedValue({
            ok: true,
            json: async () => ({
                match_count: 0,
                results: [],
            }),
        });

        document.getElementById('global-search-input')?.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }),
        );

        await Promise.resolve();

        expect(global.fetch).toHaveBeenCalledTimes(1);
        expect(global.fetch).toHaveBeenCalledWith(
            '/search?q=D',
            expect.any(Object),
        );
    });

    it('does not search while typing', async () => {
        mountSearch();

        const input = document.getElementById('global-search-input');
        input.value = 'Da';
        input.dispatchEvent(new Event('input', { bubbles: true }));

        await vi.runAllTimersAsync();

        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('does not send view or filter parameters', async () => {
        mountSearch();

        document.getElementById('global-search-input').value = '9876543210';

        global.fetch.mockResolvedValue({
            ok: true,
            json: async () => ({
                match_count: 0,
                results: [],
            }),
        });

        document.querySelector('[data-universal-search-form]')?.dispatchEvent(
            new Event('submit', { bubbles: true, cancelable: true }),
        );

        await Promise.resolve();

        const requestUrl = global.fetch.mock.calls[0][0];
        expect(requestUrl).toBe('/search?q=9876543210');
        expect(requestUrl).not.toContain('view=');
        expect(requestUrl).not.toContain('filter=');
    });
});
