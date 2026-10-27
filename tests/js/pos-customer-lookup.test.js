import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { initPosCustomerLookup } from '../../resources/js/pages/pos-customer-lookup.js';

function makeField(initial = '') {
    return { value: initial };
}

describe('initPosCustomerLookup billing fields', () => {
    let fields;
    let resultsEl;
    let statusEl;
    let searchInput;

    beforeEach(() => {
        vi.useFakeTimers();
        fields = {
            name: makeField(),
            phone: makeField(),
            email: makeField(),
            gstin: makeField(),
            billing_address: makeField('stale-address'),
            billing_city: makeField('stale-city'),
            billing_state: makeField('stale-state'),
            billing_pincode: makeField('110099'),
            place_of_supply_state: makeField('stale-pos'),
        };
        resultsEl = document.createElement('div');
        statusEl = document.createElement('p');
        searchInput = document.createElement('input');
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('clears billing fields when switching to a customer without a billing snapshot', async () => {
        global.fetch = vi.fn((url) => {
            const href = String(url);

            if (href.includes('search')) {
                const query = decodeURIComponent(href.split('q=')[1] || '');

                if (query.startsWith('92')) {
                    return Promise.resolve({
                        ok: true,
                        json: async () => ({
                            customers: [{ id: 2, name: 'Empty Billing', phone: '9222222222' }],
                        }),
                    });
                }

                return Promise.resolve({
                    ok: true,
                    json: async () => ({
                        customers: [{ id: 1, name: 'Full Billing', phone: '9111111111' }],
                    }),
                });
            }

            if (href.endsWith('/1')) {
                return Promise.resolve({
                    ok: true,
                    json: async () => ({
                        found: true,
                        id: 1,
                        name: 'Full Billing',
                        phone: '9111111111',
                        email: 'full@example.test',
                        gstin: '07AAAAA0000A1Z5',
                        billing_address: '12 Connaught Place',
                        billing_city: 'New Delhi',
                        billing_state: 'Delhi',
                        billing_pincode: '110001',
                        place_of_supply_state: 'Delhi',
                    }),
                });
            }

            if (href.endsWith('/2')) {
                return Promise.resolve({
                    ok: true,
                    json: async () => ({
                        found: true,
                        id: 2,
                        name: 'Empty Billing',
                        phone: '9222222222',
                        email: null,
                        gstin: null,
                        billing_address: null,
                        billing_city: null,
                        billing_state: null,
                        billing_pincode: null,
                        place_of_supply_state: null,
                    }),
                });
            }

            return Promise.resolve({
                ok: true,
                json: async () => ({
                    customers: [{ id: 2, name: 'Empty Billing', phone: '9222222222' }],
                }),
            });
        });

        initPosCustomerLookup({
            searchUrl: '/pos/customers/search',
            showUrlTemplate: '/pos/customers/__ID__',
            fields,
            resultsEl,
            statusEl,
            searchInputs: [searchInput],
        });

        searchInput.value = '91';
        searchInput.dispatchEvent(new Event('input', { bubbles: true }));
        await vi.advanceTimersByTimeAsync(250);
        await Promise.resolve();

        resultsEl.querySelector('button')?.click();
        await vi.waitFor(() => {
            expect(fields.billing_address.value).toBe('12 Connaught Place');
        });
        expect(fields.billing_state.value).toBe('Delhi');

        searchInput.value = '92';
        searchInput.dispatchEvent(new Event('input', { bubbles: true }));
        await vi.advanceTimersByTimeAsync(250);

        resultsEl.querySelector('button')?.click();
        await vi.waitFor(() => {
            expect(fields.billing_address.value).toBe('');
        });
        expect(fields.billing_city.value).toBe('');
        expect(fields.billing_state.value).toBe('');
        expect(fields.billing_pincode.value).toBe('');
        expect(fields.place_of_supply_state.value).toBe('');
    });
});
