export const initSystemSettingsNav = () => {
    const sidebar = document.querySelector('[data-system-settings-sidebar]');
    const links = document.querySelectorAll('[data-system-settings-nav-link]');
    const sections = document.querySelectorAll('[data-system-settings-section], #realtime-settings-card, #performance-settings-card, #category-notifications, #section-automation, #section-communication, #category-system, #section-advanced, #section-overview');

    if (! sidebar || links.length === 0) {
        return;
    }

    const sectionMap = new Map();

    links.forEach((link) => {
        const id = link.dataset.systemSettingsNavLink;
        const section = document.getElementById(id);

        if (section) {
            sectionMap.set(id, section);
        }

        link.addEventListener('click', (event) => {
            event.preventDefault();
            section?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            history.replaceState(null, '', `#${id}`);
        });
    });

    const setActive = (id) => {
        links.forEach((link) => {
            const isActive = link.dataset.systemSettingsNavLink === id;
            link.classList.toggle('system-settings-sidebar__link--active', isActive);
            link.setAttribute('aria-current', isActive ? 'true' : 'false');
        });
    };

    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver(
            (entries) => {
                const visible = entries
                    .filter((entry) => entry.isIntersecting)
                    .sort((a, b) => b.intersectionRatio - a.intersectionRatio);

                if (visible.length > 0) {
                    setActive(visible[0].target.id);
                }
            },
            { rootMargin: '-20% 0px -60% 0px', threshold: [0, 0.25, 0.5] },
        );

        sectionMap.forEach((section) => observer.observe(section));
    }

    const hash = window.location.hash.replace('#', '');

    if (hash && sectionMap.has(hash)) {
        setActive(hash);
    }
};
