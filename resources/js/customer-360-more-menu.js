const MENU_GAP_PX = 6;
const MENU_ANIM_MS = 140;
const MENU_Z_INDEX = 1085;
const MENU_MIN_WIDTH_PX = 176;

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

const resetMenuStyles = (menu) => {
    menu.classList.remove('is-open', 'is-open-up');
    menu.style.position = '';
    menu.style.top = '';
    menu.style.left = '';
    menu.style.right = '';
    menu.style.bottom = '';
    menu.style.minWidth = '';
    menu.style.zIndex = '';
    menu.style.visibility = '';
    menu.style.transformOrigin = '';
};

export const closeMenu = () => {
    if (!activeMenu || !activeToggle) {
        return;
    }

    const menu = activeMenu;
    const toggle = activeToggle;

    activeMenu = null;
    activeToggle = null;

    menu.classList.remove('is-open', 'is-open-up');
    toggle.setAttribute('aria-expanded', 'false');

    listenersAbort?.abort();
    listenersAbort = null;

    clearCloseTimer();
    closeTimer = setTimeout(() => {
        menu.hidden = true;
        resetMenuStyles(menu);
        closeTimer = null;
    }, MENU_ANIM_MS);
};

export const repositionMenu = (toggle, menu) => {
    const rect = toggle.getBoundingClientRect();
    const viewportWidth = window.innerWidth;
    const viewportHeight = window.innerHeight;
    const preferDesktop = window.matchMedia('(min-width: 576px)').matches;

    menu.style.position = 'fixed';
    menu.style.zIndex = String(MENU_Z_INDEX);
    menu.style.minWidth = `${Math.max(rect.width, MENU_MIN_WIDTH_PX)}px`;
    menu.style.visibility = 'hidden';
    menu.hidden = false;

    const menuHeight = menu.offsetHeight;
    const menuWidth = menu.offsetWidth;
    const spaceBelow = viewportHeight - rect.bottom;
    const spaceAbove = rect.top;
    const opensDown = preferDesktop
        ? spaceBelow >= menuHeight + MENU_GAP_PX || spaceBelow >= spaceAbove
        : spaceBelow >= menuHeight + MENU_GAP_PX;

    const clampedRight = Math.max(8, Math.min(rect.right, viewportWidth - 8));
    const left = Math.max(8, Math.min(clampedRight - menuWidth, viewportWidth - menuWidth - 8));

    menu.style.left = `${left}px`;
    menu.style.right = 'auto';

    if (opensDown) {
        menu.style.top = `${rect.bottom + MENU_GAP_PX}px`;
        menu.style.bottom = 'auto';
        menu.style.transformOrigin = 'top right';
        menu.classList.remove('is-open-up');
    } else {
        menu.style.top = 'auto';
        menu.style.bottom = `${viewportHeight - rect.top + MENU_GAP_PX}px`;
        menu.style.transformOrigin = 'bottom right';
        menu.classList.add('is-open-up');
    }

    menu.style.visibility = '';
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

    requestAnimationFrame(() => {
        repositionMenu(toggle, menu);

        requestAnimationFrame(() => {
            if (activeMenu === menu) {
                menu.classList.add('is-open');
            }
        });
    });

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
    window.addEventListener('resize', closeMenu, { signal });
    window.addEventListener('scroll', closeMenu, { capture: true, passive: true, signal });

    const drawerBody = document.querySelector('[data-customer-360-body]');

    drawerBody?.addEventListener('scroll', closeMenu, { passive: true, signal });
};

export const initMoreMenu = (contentHost) => {
    if (!contentHost || contentHost.dataset.c360MoreMenuInit === 'true') {
        return;
    }

    contentHost.dataset.c360MoreMenuInit = 'true';

    contentHost.addEventListener('click', (event) => {
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
                resetMenuStyles(node);
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
