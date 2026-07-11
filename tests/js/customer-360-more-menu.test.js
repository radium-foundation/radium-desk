import { afterEach, describe, expect, it } from 'vitest';
import { closeMenu, initMoreMenu, isMoreMenuOpen, openMenu } from '../../resources/js/customer-360-more-menu';

describe('customer-360-more-menu', () => {
    afterEach(() => {
        closeMenu();
        document.body.innerHTML = '';
    });

    const setupDom = () => {
        document.body.innerHTML = `
            <div data-customer-360-body style="overflow-y: auto; height: 200px;">
                <div data-customer-360-content-host>
                    <div data-c360-quick-more-wrap>
                        <button type="button" data-c360-quick-more-toggle aria-expanded="false">
                            <span>More</span>
                        </button>
                        <div data-c360-quick-more-menu role="menu" hidden>
                            <button type="button" role="menuitem">Action A</button>
                            <button type="button" role="menuitem">Action B</button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        return {
            contentHost: document.querySelector('[data-customer-360-content-host]'),
            drawerBody: document.querySelector('[data-customer-360-body]'),
            toggle: document.querySelector('[data-c360-quick-more-toggle]'),
            menu: document.querySelector('[data-c360-quick-more-menu]'),
        };
    };

    it('applies is-open synchronously when openMenu returns', () => {
        const { toggle, menu } = setupDom();

        openMenu(toggle, menu);

        expect(menu.hidden).toBe(false);
        expect(menu.classList.contains('is-open')).toBe(true);
        expect(isMoreMenuOpen()).toBe(true);
    });

    it('closes on drawer body scroll after open', () => {
        const { drawerBody, toggle, menu } = setupDom();

        openMenu(toggle, menu);
        drawerBody.dispatchEvent(new Event('scroll', { bubbles: true }));

        expect(isMoreMenuOpen()).toBe(false);
    });

    it('closes on window resize after open', () => {
        const { toggle, menu } = setupDom();

        openMenu(toggle, menu);
        window.dispatchEvent(new Event('resize'));

        expect(isMoreMenuOpen()).toBe(false);
    });

    it('opens from delegated click handler', () => {
        const { contentHost, toggle, menu } = setupDom();

        initMoreMenu(contentHost);
        toggle.dispatchEvent(new MouseEvent('click', { bubbles: true }));

        expect(menu.classList.contains('is-open')).toBe(true);
        expect(isMoreMenuOpen()).toBe(true);
    });
});
