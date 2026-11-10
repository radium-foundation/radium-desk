export const buildDashboardLiveQuery = (pageRoot, extras = {}) => {
    const fallbackQueue = extras.fallbackQueue ?? 'action_required';
    const filterWorkspaces = new Set([
        'overdue',
        'warning',
        'my_attention',
        'needs_attention',
        'high_priority',
        'pending_support',
    ]);
    const liveWorkspace = pageRoot.dataset.liveWorkspace ?? '';
    const isFilterWorkspace = filterWorkspaces.has(liveWorkspace);
    const queue = pageRoot.dataset.liveQueue ?? pageRoot.dataset.liveFilter ?? fallbackQueue;
    const filter = pageRoot.dataset.liveFilter ?? queue;
    const query = new URLSearchParams();

    Object.entries(extras).forEach(([key, value]) => {
        if (key === 'fallbackQueue' || value === undefined || value === null) {
            return;
        }

        query.set(key, String(value));
    });

    const workspace = liveWorkspace || queue;

    if (workspace) {
        query.set('workspace', workspace);
    }

    if (isFilterWorkspace) {
        return query;
    }

    if (pageRoot.dataset.liveFilter && pageRoot.dataset.liveFilter !== pageRoot.dataset.liveQueue) {
        query.set('filter', pageRoot.dataset.liveFilter);
    }

    return query;
};
