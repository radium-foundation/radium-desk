const SIDEBAR_STORAGE_KEY = 'radium.sidebarExpanded';

export const isSidebarExpanded = () => localStorage.getItem(SIDEBAR_STORAGE_KEY) === 'true';

export const applySidebarState = (expanded) => {
    document.documentElement.classList.toggle('sidebar-expanded', expanded);
};

const scrollActiveNavItemIntoView = () => {
    const nav = document.querySelector('.app-sidebar nav');
    const activeLink = nav?.querySelector('.nav-link.active');

    activeLink?.scrollIntoView({ block: 'nearest' });
};

export const initSidebar = () => {
    applySidebarState(isSidebarExpanded());
    scrollActiveNavItemIntoView();

    document.querySelectorAll('[data-sidebar-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const expanded = !document.documentElement.classList.contains('sidebar-expanded');
            applySidebarState(expanded);
            localStorage.setItem(SIDEBAR_STORAGE_KEY, expanded ? 'true' : 'false');
        });
    });
};
