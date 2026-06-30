const getSearchBanner = (card) => card?.querySelector('[data-dashboard-search-banner]') ?? null;

export const showSearchBanner = (card, { matchCount }) => {
    const banner = getSearchBanner(card);

    if (!banner) {
        return;
    }

    const title = banner.querySelector('[data-dashboard-search-banner-title]');
    const message = banner.querySelector('[data-dashboard-search-banner-message]');

    if (matchCount === 0) {
        title?.classList.add('d-none');
        if (message) {
            message.textContent = 'No matching service cases found.';
        }
    } else {
        title?.classList.remove('d-none');
        if (message) {
            const label = matchCount === 1 ? 'service case' : 'service cases';
            message.textContent = `Showing ${matchCount} matching ${label}`;
        }
    }

    banner.classList.remove('d-none');
    banner.hidden = false;
};

export const hideSearchBanner = (card) => {
    const banner = getSearchBanner(card);

    if (!banner) {
        return;
    }

    banner.classList.add('d-none');
    banner.hidden = true;
};
