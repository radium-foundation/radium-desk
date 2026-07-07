const getSearchBanner = (card) => card?.querySelector('[data-dashboard-search-banner]') ?? null;

export const showSearchBanner = (card, { matchCount = 0, query = '', error = null } = {}) => {
    const banner = getSearchBanner(card);

    if (!banner) {
        return;
    }

    const title = banner.querySelector('[data-dashboard-search-banner-title]');
    const message = banner.querySelector('[data-dashboard-search-banner-message]');
    const trimmedQuery = query.trim();

    banner.classList.toggle('dashboard-search-banner--error', Boolean(error));

    if (error) {
        title?.classList.add('d-none');
        if (message) {
            message.textContent = error;
        }
    } else if (matchCount === 0) {
        title?.classList.add('d-none');
        if (message) {
            message.textContent = trimmedQuery !== ''
                ? `No record found for ${trimmedQuery}`
                : 'No record found.';
        }
    } else {
        title?.classList.remove('d-none');
        if (message) {
            message.textContent = trimmedQuery !== ''
                ? `Showing results for ${trimmedQuery}`
                : `Showing ${matchCount} matching ${matchCount === 1 ? 'service case' : 'service cases'}`;
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

    banner.classList.remove('dashboard-search-banner--error');
    banner.classList.add('d-none');
    banner.hidden = true;
};
