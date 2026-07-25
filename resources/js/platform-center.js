export const initPlatformCenter = () => {
    const root = document.getElementById('platform-dashboard-root');

    if (! root) {
        return;
    }

    const searchInput = document.querySelector('[data-platform-search]');
    const sidebarFilter = document.querySelector('[data-platform-sidebar-filter]');
    const sections = root.querySelectorAll('[data-platform-section]');
    const cards = root.querySelectorAll('[data-platform-card]');

    const filterItems = (query) => {
        const normalized = query.trim().toLowerCase();

        sections.forEach((section) => {
            const sectionText = section.dataset.platformSearchable ?? '';
            let sectionVisible = normalized === '' || sectionText.includes(normalized);

            section.querySelectorAll('[data-platform-searchable]').forEach((item) => {
                if (item === section) {
                    return;
                }

                const itemText = item.dataset.platformSearchable ?? '';
                const matches = normalized === '' || itemText.includes(normalized);

                item.classList.toggle('settings-center-platform__card-slot--hidden', ! matches && normalized !== '');

                if (matches) {
                    sectionVisible = true;
                }
            });

            section.classList.toggle('settings-center-platform__section--hidden', ! sectionVisible && normalized !== '');
            section.classList.toggle('settings-center-search-highlight', normalized !== '' && sectionText.includes(normalized));
        });
    };

    const focusSearch = () => {
        searchInput?.focus();
        searchInput?.select();
    };

    searchInput?.addEventListener('input', () => {
        filterItems(searchInput.value);
    });

    sidebarFilter?.addEventListener('input', () => {
        const query = sidebarFilter.value.trim().toLowerCase();

        document.querySelectorAll('[data-platform-sidebar-item]').forEach((item) => {
            const label = item.dataset.platformSidebarLabel ?? '';
            item.hidden = query !== '' && ! label.includes(query);
        });
    });

    document.addEventListener('keydown', (event) => {
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
            const target = event.target;

            if (target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target?.isContentEditable) {
                return;
            }

            event.preventDefault();
            focusSearch();
        }
    });

    document.querySelectorAll('[data-platform-sidebar-link]').forEach((link) => {
        link.addEventListener('click', (event) => {
            const href = link.getAttribute('href');

            if (! href?.startsWith('#')) {
                return;
            }

            event.preventDefault();
            const target = document.querySelector(href);

            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                document.querySelectorAll('[data-platform-sidebar-link]').forEach((item) => {
                    item.classList.toggle('settings-center-sidebar__link--active', item === link);
                });
            }
        });
    });

    const refreshAllButton = document.querySelector('[data-platform-refresh-all]');

    refreshAllButton?.addEventListener('click', async () => {
        const icon = refreshAllButton.querySelector('i');

        refreshAllButton.disabled = true;
        icon?.classList.add('spin');

        try {
            await window.RadiumDesk?.platformDashboard?.refreshAll?.(root);
        } finally {
            refreshAllButton.disabled = false;
            icon?.classList.remove('spin');
        }
    });

    cards.forEach((card) => {
        card.classList.add('settings-center-platform-card--loaded');
    });
};
