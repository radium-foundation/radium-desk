import { initDashboardActivityStreams } from './dashboard-activity-streams';
import { createVisibilityAwarePoller } from './polling/visibility-aware-poller';

const REFRESH_CONTROLLER = Symbol('dashboardActivityRefreshController');

let refreshInFlight = false;
let activePoller = null;

const readPollIntervalMs = (feed) => {
    const intervalMs = Number(feed?.dataset.activityPollIntervalMs ?? 60000);

    return intervalMs > 0 ? intervalMs : 60000;
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

export const initDashboardActivityRefresh = (pageRoot) => {
    const feed = pageRoot?.querySelector?.('[data-dashboard-activity-feed]');

    if (!feed?.dataset.activityRefreshUrl) {
        return null;
    }

    pageRoot[REFRESH_CONTROLLER]?.destroy?.();
    activePoller?.stop?.();

    activePoller = createVisibilityAwarePoller({
        getIntervalMs: () => {
            const activeFeed = pageRoot.querySelector('[data-dashboard-activity-feed]');

            return readPollIntervalMs(activeFeed);
        },
        shouldRun: () => Boolean(pageRoot.querySelector('[data-dashboard-activity-feed]')),
        tick: async () => {
            const feedToRefresh = pageRoot.querySelector('[data-dashboard-activity-feed]');

            if (!feedToRefresh) {
                return;
            }

            await refreshActivityFeed(pageRoot, feedToRefresh);
        },
    });

    const refreshController = {
        destroy: () => {
            activePoller?.stop?.();
            activePoller = null;

            if (pageRoot[REFRESH_CONTROLLER] === refreshController) {
                delete pageRoot[REFRESH_CONTROLLER];
            }
        },
    };

    pageRoot[REFRESH_CONTROLLER] = refreshController;

    return refreshController;
};

export const resetDashboardActivityRefreshStateForTests = () => {
    refreshInFlight = false;
    activePoller?.stop?.();
    activePoller = null;
};
