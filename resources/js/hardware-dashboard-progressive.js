export const parsePositiveInt = (value, fallback) => {
    const parsed = Number.parseInt(String(value ?? ''), 10);
    return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
};

export const buildHardwareRowsUrl = (workspace, { page, origin = 'https://desk.example' } = {}) => {
    const url = new URL(workspace.dataset.hardwareRowsUrl, origin);
    const params = new URLSearchParams(window?.location?.search ?? '');

    const scope = workspace.dataset.hardwareScope || 'active';
    const filter = workspace.dataset.hardwareFilter || 'needs_action';
    const pageNum = page ?? workspace.dataset.hardwarePage ?? '1';
    const search = workspace.dataset.hardwareSearch
        || params.get('q')
        || '';

    url.searchParams.set('hw_scope', scope);
    url.searchParams.set('hw_filter', filter);
    url.searchParams.set('hw_page', String(pageNum));

    if (workspace.dataset.hardwareProgressive === '1') {
        url.searchParams.set('hw_progressive', '1');
    }

    if (search !== '') {
        url.searchParams.set('q', search);
    }

    return url;
};

export const shouldAcceptRowsResponse = (requestGeneration, currentGeneration) => requestGeneration === currentGeneration;

export const hardwareLastPage = (total, initialPerPage = 10, standardPerPage = 40) => {
    if (total <= 0) {
        return 1;
    }
    if (total <= initialPerPage) {
        return 1;
    }

    const remaining = total - initialPerPage;

    return 1 + Math.ceil(remaining / standardPerPage);
};
