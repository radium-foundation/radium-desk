import { initDashboardActivityStreams } from './dashboard-activity-streams';

const REFRESH_CONTROLLER = Symbol('dashboardActivityRefreshController');

let refreshInFlight = false;
let pollTimeoutId = null;

const readPollIntervalMs = (feed) => {
    const intervalMs = Number(feed?.dataset.activityPollIntervalMs ?? 30000);

    return intervalMs > 0 ? intervalMs : 30000;
};

const applyActivityHtml = (pageRoot, feed, html) => {
    const template = document.createElement('template');
    template.innerHTML = html.trim();

    const nextFeed = template.content.querySelector('[data-dashboard-activity-feed]');

    if (!nextFeed) {
        return;
    }

    feed.replaceWith(nextFeed);
    initDashboardActivityStreams(pageRoot);
};

const refreshActivityFeed = async (pageRoot, feed) => {
    const refreshUrl = feed.dataset.activityRefreshUrl;

    if (!refreshUrl || document.hidden || refreshInFlight) {
        return;
    }

    refreshInFlight = true;

    try {
        const response = await fetch(refreshUrl, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            return;
        }

        const data = await response.json();
        const currentFeed = pageRoot.querySelector('[data-dashboard-activity-feed]');

        if (!currentFeed) {
            return;
        }

        if (data.empty || !data.html) {
            currentFeed.remove();

            return;
        }

        if (data.html === currentFeed.outerHTML) {
            return;
        }

        applyActivityHtml(pageRoot, currentFeed, data.html);
    } catch {
        // Ignore transient network errors during background refresh.
    } finally {
        refreshInFlight = false;
    }
};

const scheduleNextPoll = (pageRoot, feed, controller) => {
    if (controller.signal.aborted) {
        return;
    }

    if (pollTimeoutId !== null) {
        window.clearTimeout(pollTimeoutId);
        pollTimeoutId = null;
    }

    const activeFeed = pageRoot.querySelector('[data-dashboard-activity-feed]');

    if (!activeFeed) {
        return;
    }

    pollTimeoutId = window.setTimeout(async () => {
        pollTimeoutId = null;

        if (controller.signal.aborted) {
            return;
        }

        const feedToRefresh = pageRoot.querySelector('[data-dashboard-activity-feed]');

        if (!feedToRefresh) {
            return;
        }

        await refreshActivityFeed(pageRoot, feedToRefresh);
        scheduleNextPoll(pageRoot, feedToRefresh, controller);
    }, readPollIntervalMs(activeFeed));
};

export const initDashboardActivityRefresh = (pageRoot) => {
    const feed = pageRoot?.querySelector?.('[data-dashboard-activity-feed]');

    if (!feed?.dataset.activityRefreshUrl) {
        return null;
    }

    pageRoot[REFRESH_CONTROLLER]?.destroy?.();

    const controller = new AbortController();

    const refreshController = {
        destroy: () => {
            controller.abort();

            if (pollTimeoutId !== null) {
                window.clearTimeout(pollTimeoutId);
                pollTimeoutId = null;
            }

            if (pageRoot[REFRESH_CONTROLLER] === refreshController) {
                delete pageRoot[REFRESH_CONTROLLER];
            }
        },
    };

    pageRoot[REFRESH_CONTROLLER] = refreshController;
    scheduleNextPoll(pageRoot, feed, controller);

    return refreshController;
};

export const resetDashboardActivityRefreshStateForTests = () => {
    refreshInFlight = false;

    if (pollTimeoutId !== null) {
        window.clearTimeout(pollTimeoutId);
        pollTimeoutId = null;
    }
};
