import { initIncomingEmailModal } from './incoming-email-modal';

export const TIMELINE_FILTER_EMPTY_MESSAGES = {
    all: 'No customer activity recorded yet.',
    system: 'No system events',
    customer: 'No customer events',
    support: 'No support events',
    notifications: 'No notification events',
    synchronization: 'No synchronization events',
    appointments: 'No appointment events',
    payments: 'No payment events',
};

const SEARCH_DEBOUNCE_MS = 300;

const parseFilterEmptyMessages = (timeline) => {
    const template = timeline.querySelector('[data-timeline-filter-empty-messages]');

    if (!template?.textContent) {
        return TIMELINE_FILTER_EMPTY_MESSAGES;
    }

    try {
        return {
            ...TIMELINE_FILTER_EMPTY_MESSAGES,
            ...JSON.parse(template.textContent),
        };
    } catch {
        return TIMELINE_FILTER_EMPTY_MESSAGES;
    }
};

const timelineEventSelector = '[data-timeline-event]';

export const applyTimelineFilter = (timeline, filterKey, emptyMessages) => {
    const events = timeline.querySelectorAll(timelineEventSelector);
    let visibleCount = 0;

    events.forEach((eventNode) => {
        const filterTags = (eventNode.dataset.timelineFilter ?? '')
            .split(',')
            .map((tag) => tag.trim())
            .filter((tag) => tag !== '');
        const isVisible = filterKey === 'all' || filterTags.includes(filterKey);

        eventNode.classList.toggle('is-filter-hidden', !isVisible);
        eventNode.hidden = !isVisible;

        if (isVisible) {
            visibleCount += 1;
        }
    });

    timeline.querySelectorAll('[data-timeline-group]').forEach((group) => {
        const visibleEvents = group.querySelectorAll(`${timelineEventSelector}:not(.is-filter-hidden)`);
        group.classList.toggle('is-filter-empty', visibleEvents.length === 0);
        group.hidden = visibleEvents.length === 0;
    });

    const filterEmpty = timeline.querySelector('[data-timeline-filter-empty]');
    const globalEmpty = timeline.querySelector('[data-timeline-global-empty]');
    const list = timeline.querySelector('[data-timeline-list]');
    const loadMoreWrap = timeline.querySelector('[data-timeline-load-more-wrap]');

    if (filterEmpty) {
        const message = emptyMessages[filterKey] ?? emptyMessages.all;
        const messageTarget = filterEmpty.querySelector('[data-c360-empty-message]') ?? filterEmpty;

        if (filterKey !== 'all' && events.length > 0 && visibleCount === 0) {
            messageTarget.textContent = message;
            filterEmpty.classList.remove('d-none');
            filterEmpty.hidden = false;
        } else {
            messageTarget.textContent = '';
            filterEmpty.classList.add('d-none');
            filterEmpty.hidden = true;
        }
    }

    if (list) {
        list.hidden = filterKey !== 'all' && events.length > 0 && visibleCount === 0;
    }

    if (globalEmpty) {
        globalEmpty.hidden = filterKey !== 'all';
    }

    if (loadMoreWrap) {
        loadMoreWrap.hidden = filterKey !== 'all';
    }
};

const logTimelineFailure = (endpoint, status, error = null) => {
    if (!import.meta.env?.DEV) {
        return;
    }

    console.error('[Customer 360] Timeline request failed', {
        endpoint,
        status,
        error,
    });
};

const showTimelineRequestError = (timeline, message) => {
    let errorNode = timeline.querySelector('[data-timeline-request-error]');

    if (!errorNode) {
        errorNode = document.createElement('div');
        errorNode.className = 'alert alert-danger py-2 px-3 mb-2';
        errorNode.setAttribute('data-timeline-request-error', '');
        errorNode.setAttribute('role', 'alert');
        timeline.prepend(errorNode);
    }

    errorNode.textContent = message;
    errorNode.hidden = false;
};

const clearTimelineRequestError = (timeline) => {
    const errorNode = timeline.querySelector('[data-timeline-request-error]');

    if (!errorNode) {
        return;
    }

    errorNode.textContent = '';
    errorNode.hidden = true;
};

const buildTimelineUrl = (baseUrl, { offset = 0, query = '' } = {}) => {
    const url = new URL(baseUrl, window.location.origin);
    url.searchParams.set('offset', String(offset));

    if (query.trim() !== '') {
        url.searchParams.set('q', query.trim());
    } else {
        url.searchParams.delete('q');
    }

    return url.toString();
};

const appendIncomingTimelineGroups = (list, fragment) => {
    fragment.querySelectorAll('[data-timeline-group]').forEach((incomingGroup) => {
        const bucket = incomingGroup.dataset.timelineGroup;
        const existingGroup = list.querySelector(`[data-timeline-group="${bucket}"]`);

        if (existingGroup) {
            const itemsHost = existingGroup.querySelector('.unified-timeline-group-items');

            incomingGroup
                .querySelectorAll('.unified-timeline-group-items > [data-timeline-event], .unified-timeline-group-items > [data-timeline-milestone]')
                .forEach((eventNode) => {
                    itemsHost?.appendChild(eventNode);
                });

            return;
        }

        list.appendChild(incomingGroup);
    });
};

const bindTimelineFilters = (timeline) => {
    const filterHost = timeline.querySelector('[data-timeline-filters]');

    if (!filterHost || filterHost.dataset.timelineFiltersBound === 'true') {
        return;
    }

    filterHost.dataset.timelineFiltersBound = 'true';

    const emptyMessages = parseFilterEmptyMessages(timeline);
    let activeFilter = 'all';

    filterHost.addEventListener('click', (event) => {
        const chip = event.target.closest('[data-timeline-filter-chip]');

        if (!chip || !filterHost.contains(chip)) {
            return;
        }

        activeFilter = chip.dataset.timelineFilterChip ?? 'all';

        filterHost.querySelectorAll('[data-timeline-filter-chip]').forEach((button) => {
            const isActive = button === chip;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });

        applyTimelineFilter(timeline, activeFilter, emptyMessages);
    });

    applyTimelineFilter(timeline, activeFilter, emptyMessages);
};

const bindTimelineSearch = (timeline, root) => {
    const searchInput = timeline.querySelector('[data-timeline-search]');

    if (!searchInput || searchInput.dataset.timelineSearchBound === 'true') {
        return;
    }

    searchInput.dataset.timelineSearchBound = 'true';

    const baseUrl = timeline.dataset.timelineBaseUrl
        ?? timeline.querySelector('[data-timeline-load-more]')?.dataset.timelineLoadMoreUrl
        ?? '';

    if (baseUrl === '') {
        return;
    }

    let debounceTimer = null;
    let activeController = null;

    const runSearch = async (query) => {
        if (activeController) {
            activeController.abort();
        }

        activeController = new AbortController();
        const requestUrl = buildTimelineUrl(baseUrl, { offset: 0, query });

        try {
            const response = await fetch(requestUrl, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: activeController.signal,
            });

            if (!response.ok) {
                logTimelineFailure(requestUrl, response.status);
                showTimelineRequestError(timeline, 'Unable to search timeline. Please try again.');

                return;
            }

            clearTimelineRequestError(timeline);

            const payload = await response.json();
            const section = timeline.closest('[data-customer-360-timeline-section]');

            if (section && typeof payload.html === 'string') {
                section.outerHTML = payload.html;
                initUnifiedTimeline(root);

                return;
            }

            if (typeof payload.html === 'string') {
                timeline.outerHTML = payload.html;
                initUnifiedTimeline(root);
            }
        } catch (error) {
            if (error?.name === 'AbortError') {
                return;
            }

            logTimelineFailure(requestUrl, null, error);
            showTimelineRequestError(timeline, 'Unable to search timeline. Please try again.');
        }
    };

    searchInput.addEventListener('input', () => {
        window.clearTimeout(debounceTimer);
        debounceTimer = window.setTimeout(() => {
            runSearch(searchInput.value ?? '');
        }, SEARCH_DEBOUNCE_MS);
    });
};

export const initUnifiedTimeline = (root = document) => {
    initIncomingEmailModal(root);

    const timelines = root.querySelectorAll('[data-unified-timeline]');

    timelines.forEach((timeline) => {
        if (timeline.dataset.timelineBound === 'true') {
            bindTimelineFilters(timeline);
            bindTimelineSearch(timeline, root);

            return;
        }

        timeline.dataset.timelineBound = 'true';
        bindTimelineFilters(timeline);
        bindTimelineSearch(timeline, root);

        timeline.addEventListener('click', async (event) => {
            const button = event.target.closest('[data-timeline-load-more]');

            if (!button || !timeline.contains(button)) {
                return;
            }

            event.preventDefault();

            const loadMoreUrl = button.dataset.timelineLoadMoreUrl;
            const offset = Number.parseInt(button.dataset.timelineOffset ?? '0', 10);
            const query = button.dataset.timelineQuery
                ?? timeline.querySelector('[data-timeline-search]')?.value
                ?? '';

            if (!loadMoreUrl || Number.isNaN(offset)) {
                return;
            }

            button.disabled = true;

            try {
                const requestUrl = buildTimelineUrl(loadMoreUrl, { offset, query });
                const response = await fetch(requestUrl, {
                    headers: {
                        Accept: 'text/html',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) {
                    logTimelineFailure(requestUrl, response.status);
                    showTimelineRequestError(timeline, 'Unable to load older timeline events. Please try again.');

                    return;
                }

                clearTimelineRequestError(timeline);

                const html = await response.text();
                const parser = new DOMParser();
                const fragment = parser.parseFromString(html, 'text/html');
                const list = timeline.querySelector('[data-timeline-list]');
                const loadMoreWrap = timeline.querySelector('[data-timeline-load-more-wrap]');

                if (!list) {
                    return;
                }

                appendIncomingTimelineGroups(list, fragment);

                const incomingLoadMore = fragment.querySelector('[data-timeline-load-more]');
                const incomingWrap = fragment.querySelector('[data-timeline-load-more-wrap]');

                if (loadMoreWrap && incomingWrap) {
                    if (incomingLoadMore) {
                        loadMoreWrap.replaceWith(incomingWrap);
                    } else {
                        loadMoreWrap.remove();
                    }
                }

                const activeChip = timeline.querySelector('[data-timeline-filter-chip].is-active');
                const activeFilter = activeChip?.dataset.timelineFilterChip ?? 'all';
                applyTimelineFilter(timeline, activeFilter, parseFilterEmptyMessages(timeline));
            } catch (error) {
                logTimelineFailure(buildTimelineUrl(loadMoreUrl, { offset, query }), null, error);
                showTimelineRequestError(timeline, 'Unable to load older timeline events. Please try again.');
            } finally {
                const activeButton = timeline.querySelector('[data-timeline-load-more]');

                if (activeButton) {
                    activeButton.disabled = false;
                }
            }
        });
    });
};
