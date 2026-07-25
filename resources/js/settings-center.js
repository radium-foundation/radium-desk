export const initSettingsCenterNav = () => {
    const filterInput = document.querySelector('[data-settings-center-nav-filter]');

    if (! filterInput) {
        return;
    }

    filterInput.addEventListener('input', () => {
        const query = filterInput.value.trim().toLowerCase();

        document.querySelectorAll('[data-settings-nav-item]').forEach((item) => {
            const label = item.dataset.settingsNavLabel ?? '';
            item.hidden = query !== '' && ! label.includes(query);
        });

        document.querySelectorAll('[data-settings-nav-group]').forEach((group) => {
            const visibleItems = group.querySelectorAll('[data-settings-nav-item]:not([hidden])');
            group.hidden = visibleItems.length === 0;
        });
    });

    const hash = window.location.hash.replace('#', '');

    if (hash) {
        const target = document.getElementById(hash);

        if (target) {
            setTimeout(() => {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 100);
        }
    }
};

