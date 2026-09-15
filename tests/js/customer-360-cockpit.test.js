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

    const setupCockpitWithCallLink = () => {
        const { drawer, contentHost, options } = setupCockpit();

        contentHost.querySelector('[data-customer-360-content]')?.insertAdjacentHTML(
            'beforeend',
            '<a href="tel:+919876543210" data-c360-shortcut-action="call">Call</a>',
        );

        const callLink = contentHost.querySelector('a[data-c360-shortcut-action="call"]');
        const callClick = vi.fn();
        callLink?.addEventListener('click', (event) => {
            event.preventDefault();
            callClick();
        });

        const api = initCustomer360Cockpit(options);

        return { api, callLink, callClick };
    };

    it('copies with Command+C and does not start an outbound call', () => {
        const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response());
        const { api, callClick } = setupCockpitWithCallLink();

        const event = new KeyboardEvent('keydown', {
            key: 'c',
            metaKey: true,
            bubbles: true,
            cancelable: true,
        });
        document.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);
        expect(callClick).not.toHaveBeenCalled();
        expect(fetchSpy).not.toHaveBeenCalled();

        api?.destroy();
    });

    it('copies with Ctrl+C and does not start an outbound call', () => {
        const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response());
        const { api, callClick } = setupCockpitWithCallLink();

        const event = new KeyboardEvent('keydown', {
            key: 'c',
            ctrlKey: true,
            bubbles: true,
            cancelable: true,
        });
        document.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);
        expect(callClick).not.toHaveBeenCalled();
        expect(fetchSpy).not.toHaveBeenCalled();

        api?.destroy();
    });

    it('does not intercept copy while text is selected in a typing target', () => {
        const { api, callClick } = setupCockpitWithCallLink();
        const textarea = document.createElement('textarea');
        textarea.value = 'selected serial WD07281157';
        document.body.appendChild(textarea);
        textarea.focus();
        textarea.setSelectionRange(0, textarea.value.length);

        const event = new KeyboardEvent('keydown', {
            key: 'c',
            metaKey: true,
            bubbles: true,
            cancelable: true,
        });
        textarea.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);
        expect(callClick).not.toHaveBeenCalled();

        api?.destroy();
    });

    it('still places a tel call on unmodified C', () => {
        const { api, callClick } = setupCockpitWithCallLink();

        const event = new KeyboardEvent('keydown', {
            key: 'c',
            bubbles: true,
            cancelable: true,
        });
        document.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
        expect(callClick).toHaveBeenCalledTimes(1);

        api?.destroy();
    });
});
