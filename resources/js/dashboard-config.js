export const getDashboardPageRoot = () => document.getElementById('dashboard-page');

export const getDashboardConfig = () => {
    const pageRoot = getDashboardPageRoot();

    if (!pageRoot) {
        return null;
    }

    return {
        pageRoot,
        dashboardLoadMoreUrl: pageRoot.dataset.dashboardLoadMoreUrl ?? '',
        dashboardSearchRowsUrl: pageRoot.dataset.dashboardSearchRowsUrl ?? '',
    };
};
