export const buildDashboardLiveQuery = (pageRoot, extras = {}) => {
    const fallbackQueue = extras.fallbackQueue ?? 'attention';
    const queue = pageRoot.dataset.liveQueue ?? pageRoot.dataset.liveFilter ?? fallbackQueue;
    const filter = pageRoot.dataset.liveFilter ?? queue;
    const query = new URLSearchParams();

    Object.entries(extras).forEach(([key, value]) => {
        if (key === 'fallbackQueue' || value === undefined || value === null) {
            return;
        }

        query.set(key, String(value));
    });

    if (pageRoot.dataset.liveQueue) {
        query.set('queue', pageRoot.dataset.liveQueue);
    } else {
        query.set('queue', queue);
    }

    if (pageRoot.dataset.liveFilter && pageRoot.dataset.liveFilter !== pageRoot.dataset.liveQueue) {
        query.set('filter', pageRoot.dataset.liveFilter);
    } else if (! pageRoot.dataset.liveQueue) {
        query.set('filter', filter);
    }

    return query;
};
