import { afterEach, describe, expect, it, vi } from 'vitest';
import { initCustomer360Cockpit } from '../../resources/js/customer-360-cockpit';

describe('initCustomer360Cockpit', () => {
    afterEach(() => {
        document.body.innerHTML = '';
        vi.restoreAllMocks();
    });

    const setupCockpit = () => {
        document.body.innerHTML = `
            <div data-customer-360-drawer>
                <div data-c360-command-palette hidden>
                    <input data-c360-command-palette-input />
                    <ul data-c360-command-palette-results></ul>
                    <div data-c360-command-palette-backdrop></div>
                </div>
                <div data-c360-shortcut-help hidden>
                    <div data-c360-shortcut-help-backdrop></div>
                    <button type="button" data-c360-shortcut-help-close></button>
                </div>
                <div data-customer-360-content-host>
                    <div data-customer-360-content>
                        <button type="button" data-c360-empty-open-tab="timeline">Open timeline</button>
                        <button type="button" data-customer-360-tab="timeline">Timeline</button>
                    </div>
                </div>
            </div>
        `;

        const drawer = document.querySelector('[data-customer-360-drawer]');
        const contentHost = document.querySelector('[data-customer-360-content-host]');

        return {
            drawer,
            contentHost,
            options: {
                drawer,
                contentHost,
                activateTab: vi.fn(),
                isOpen: () => true,
            },
        };
    };

    it('removes cockpit listeners when destroy is called before re-init', () => {
        const { contentHost, options } = setupCockpit();
        let timelineTabClicks = 0;

        contentHost.querySelector('[data-customer-360-tab="timeline"]')?.addEventListener('click', () => {
            timelineTabClicks += 1;
        });

        const api = initCustomer360Cockpit(options);
        api?.destroy();
        initCustomer360Cockpit(options);

        contentHost.querySelector('[data-c360-empty-open-tab]')?.dispatchEvent(
            new MouseEvent('click', { bubbles: true }),
        );

        expect(timelineTabClicks).toBe(1);
    });
});
