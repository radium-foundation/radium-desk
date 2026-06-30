const scrollToServiceCasesPanel = () => {
    document.getElementById('dashboard-service-cases-panel')
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
};

export const initDashboardKpiActions = (pageRoot = document) => {
    pageRoot.addEventListener('click', (event) => {
        const item = event.target.closest('[data-dashboard-kpi-action="focus-service-cases-all"]');

        if (!item) {
            return;
        }

        const href = item.getAttribute('href');

        if (!href) {
            return;
        }

        const targetUrl = new URL(href, window.location.origin);
        const currentUrl = new URL(window.location.href);

        if (targetUrl.pathname === currentUrl.pathname && targetUrl.search === currentUrl.search) {
            event.preventDefault();
            scrollToServiceCasesPanel();
        }
    });

    if (window.location.hash === '#dashboard-service-cases-panel') {
        requestAnimationFrame(() => {
            scrollToServiceCasesPanel();
        });
    }
};
