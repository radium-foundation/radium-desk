const MENU_ANIM_MS = 140;

/** @type {HTMLElement | null} */
let activeMenu = null;

/** @type {HTMLElement | null} */
let activeToggle = null;

/** @type {AbortController | null} */
let listenersAbort = null;

let closeTimer = null;

export const isMoreMenuOpen = () => activeMenu !== null && !activeMenu.hidden;

const clearCloseTimer = () => {
    if (closeTimer !== null) {
        clearTimeout(closeTimer);
        closeTimer = null;
    }
};

export const closeMenu = () => {
    if (!activeMenu || !activeToggle) {
        return;
    }

    const menu = activeMenu;
    const toggle = activeToggle;

    activeMenu = null;
    activeToggle = null;

    menu.classList.remove('is-open');
    toggle.setAttribute('aria-expanded', 'false');

    listenersAbort?.abort();
    listenersAbort = null;

    clearCloseTimer();
    closeTimer = setTimeout(() => {
        menu.hidden = true;
        closeTimer = null;
    }, MENU_ANIM_MS);
};

export const openMenu = (toggle, menu) => {
    if (!(toggle instanceof HTMLElement) || !(menu instanceof HTMLElement)) {
        return;
    }

    if (activeMenu === menu && isMoreMenuOpen()) {
        closeMenu();

        return;
    }

    closeMenu();

    activeToggle = toggle;
    activeMenu = menu;

    toggle.setAttribute('aria-expanded', 'true');
    menu.hidden = false;
    menu.classList.add('is-open');

    listenersAbort?.abort();
    listenersAbort = new AbortController();
    const { signal } = listenersAbort;

    const handleOutsidePointer = (event) => {
        if (!(event.target instanceof Element)) {
            return;
        }

        if (menu.contains(event.target) || toggle.contains(event.target)) {
            return;
        }

        closeMenu();
    };

    const handleEscape = (event) => {
        if (event.key !== 'Escape') {
            return;
        }

        event.stopPropagation();
        closeMenu();
    };

    document.addEventListener('pointerdown', handleOutsidePointer, { capture: true, signal });
    document.addEventListener('keydown', handleEscape, { signal });

    const drawerBody = document.querySelector('[data-customer-360-body]');

    drawerBody?.addEventListener('scroll', closeMenu, { passive: true, signal });
};

export const openMoreMenuForHost = (contentHost) => {
    if (!(contentHost instanceof HTMLElement)) {
        return false;
    }

    const toggle = contentHost.querySelector('[data-c360-quick-more-toggle]');
    const menu = toggle?.closest('[data-c360-quick-more-wrap]')?.querySelector('[data-c360-quick-more-menu]');

    if (!(toggle instanceof HTMLElement) || !(menu instanceof HTMLElement)) {
        return false;
    }

    openMenu(toggle, menu);

    return true;
};

export const initMoreMenu = (contentHost) => {
    if (!contentHost || contentHost.dataset.c360MoreMenuInit === 'true') {
        return;
    }

    contentHost.dataset.c360MoreMenuInit = 'true';

    contentHost.addEventListener('click', (event) => {
        const tabItem = event.target.closest('[data-c360-overflow-tab]');

        if (tabItem instanceof HTMLElement && contentHost.contains(tabItem)) {
            event.preventDefault();
            event.stopPropagation();

            const tabKey = tabItem.dataset.c360OverflowTab ?? 'overview';
            const anchor = tabItem.dataset.c360OverflowAnchor ?? null;
            const tabButton = contentHost.querySelector(`[data-customer-360-tab="${tabKey}"]`);

            tabButton?.click();

            if (anchor) {
                window.setTimeout(() => {
                    contentHost.querySelector(`#${CSS.escape(anchor)}`)
                        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 120);
            }

            closeMenu();

            return;
        }

        const menuItem = event.target.closest('[data-c360-quick-more-menu] [role="menuitem"]');

        if (menuItem && contentHost.contains(menuItem) && isMoreMenuOpen()) {
            closeMenu();

            return;
        }

        const toggle = event.target.closest('[data-c360-quick-more-toggle]');

        if (!toggle || !contentHost.contains(toggle)) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        const wrap = toggle.closest('[data-c360-quick-more-wrap]');
        const menu = wrap?.querySelector('[data-c360-quick-more-menu]');

        if (!(menu instanceof HTMLElement)) {
            return;
        }

        if (activeMenu === menu && isMoreMenuOpen()) {
            closeMenu();

            return;
        }

        contentHost.querySelectorAll('[data-c360-quick-more-menu]').forEach((node) => {
            if (node instanceof HTMLElement && node !== menu) {
                node.hidden = true;
                node.classList.remove('is-open');
            }
        });

        contentHost.querySelectorAll('[data-c360-quick-more-toggle]').forEach((node) => {
            if (node instanceof HTMLElement && node !== toggle) {
                node.setAttribute('aria-expanded', 'false');
            }
        });

        openMenu(toggle, menu);
    });
};
