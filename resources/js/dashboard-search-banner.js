const getSearchBanner = (card) => card?.querySelector('[data-dashboard-search-banner]') ?? null;

export const resolveSearchBannerMessage = ({
    matchCount = 0,
    query = '',
    error = null,
    intake = null,
} = {}) => {
    if (error) {
        return error;
    }

    if (intake?.requires_confirmation && intake?.legacy_preview) {
        return 'Legacy order found — create service request';
    }

    if (intake?.classification === 'new_contact') {
        return 'No existing record — create new service request';
    }

    if ((intake?.matches ?? []).length > 0) {
        return 'Desk record found — create service request';
    }

    if (matchCount === 0) {
        const trimmedQuery = query.trim();

        return trimmedQuery !== ''
            ? `No record found for ${trimmedQuery}`
            : 'No record found.';
    }

    const trimmedQuery = query.trim();

    return trimmedQuery !== ''
        ? `Showing results for ${trimmedQuery}`
        : `Showing ${matchCount} matching ${matchCount === 1 ? 'service case' : 'service cases'}`;
};

export const showSearchBanner = (card, {
    matchCount = 0,
    query = '',
    error = null,
    intake = null,
} = {}) => {
    const banner = getSearchBanner(card);

    if (!banner) {
        return;
    }

    const title = banner.querySelector('[data-dashboard-search-banner-title]');
    const message = banner.querySelector('[data-dashboard-search-banner-message]');
    const bannerMessage = resolveSearchBannerMessage({ matchCount, query, error, intake });

    banner.classList.toggle('dashboard-search-banner--error', Boolean(error));

    if (error || matchCount === 0) {
        title?.classList.add('d-none');
    } else {
        title?.classList.remove('d-none');
    }

    if (message) {
        message.textContent = bannerMessage;
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
