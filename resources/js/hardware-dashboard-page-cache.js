const pageCache = new Map();

const cacheKey = (workspace, page) => {
    const scope = workspace.dataset.hardwareScope || 'active';
    const filter = workspace.dataset.hardwareFilter || 'needs_action';
    const search = workspace.dataset.hardwareSearch || '';

    return `${scope}|${filter}|${search}|${page}`;
};

const prune = (maxEntries) => {
    while (pageCache.size > maxEntries) {
        const oldestKey = pageCache.keys().next().value;
        pageCache.delete(oldestKey);
    }
};

export const readHardwarePageCache = (workspace, page) => {
    const entry = pageCache.get(cacheKey(workspace, page));
    if (!entry) {
        return null;
    }

    return {
        ...entry,
        key: cacheKey(workspace, page),
    };
};

export const writeHardwarePageCache = (workspace, page, payload) => {
    const maxEntries = Number.parseInt(workspace.dataset.hardwarePageCacheMax ?? '6', 10) || 6;
    pageCache.set(cacheKey(workspace, page), {
        payload,
        rowsHtml: payload.rows_html ?? '',
        paginationHtml: payload.pagination_html ?? '',
        cachedAt: Date.now(),
    });
    prune(maxEntries);
};

export const clearHardwarePageCache = (workspace) => {
    const prefix = [
        workspace.dataset.hardwareScope || 'active',
        workspace.dataset.hardwareFilter || 'needs_action',
        workspace.dataset.hardwareSearch || '',
    ].join('|');

    for (const key of [...pageCache.keys()]) {
        if (key.startsWith(prefix)) {
            pageCache.delete(key);
        }
    }
};

export const resetHardwarePageCacheForTests = () => {
    pageCache.clear();
};