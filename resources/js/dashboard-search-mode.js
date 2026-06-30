let dashboardSearchActive = false;

export const isDashboardSearchActive = () => dashboardSearchActive;

export const setDashboardSearchActive = (active) => {
    dashboardSearchActive = Boolean(active);
};

export const resetDashboardSearchMode = () => {
    dashboardSearchActive = false;
};
