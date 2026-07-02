export const TIMELINE_FILTER_EMPTY_MESSAGES = {
    all: 'No customer activity recorded yet.',
    whatsapp: 'No WhatsApp activity',
    payments: 'No Payments',
    repairs: 'No Repairs',
    notes: 'No Notes',
    assignments: 'No Assignments',
    audit: 'No Audit Events',
};

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

export const applyTimelineFilter = (timeline, filterKey, emptyMessages) => {
    const events = timeline.querySelectorAll('[data-timeline-event]');
    let visibleCount = 0;

    events.forEach((eventNode) => {
        const eventFilter = eventNode.dataset.timelineFilter ?? '';
        const isVisible = filterKey === 'all' || eventFilter === filterKey;

        eventNode.classList.toggle('is-filter-hidden', !isVisible);
        eventNode.hidden = !isVisible;

        if (isVisible) {
            visibleCount += 1;
        }
    });

    timeline.querySelectorAll('[data-timeline-group]').forEach((group) => {
        const visibleEvents = group.querySelectorAll('[data-timeline-event]:not(.is-filter-hidden)');
        group.classList.toggle('is-filter-empty', visibleEvents.length === 0);
        group.hidden = visibleEvents.length === 0;
    });

    const filterEmpty = timeline.querySelector('[data-timeline-filter-empty]');
    const globalEmpty = timeline.querySelector('[data-timeline-global-empty]');
    const list = timeline.querySelector('[data-timeline-list]');
    const loadMoreWrap = timeline.querySelector('[data-timeline-load-more-wrap]');

    if (filterEmpty) {
        const message = emptyMessages[filterKey] ?? emptyMessages.all;

        if (filterKey !== 'all' && events.length > 0 && visibleCount === 0) {
            filterEmpty.textContent = message;
            filterEmpty.classList.remove('d-none');
            filterEmpty.hidden = false;
        } else {
            filterEmpty.textContent = '';
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

export const initUnifiedTimeline = (root = document) => {
    const timelines = root.querySelectorAll('[data-unified-timeline]');

    timelines.forEach((timeline) => {
        if (timeline.dataset.timelineBound === 'true') {
            bindTimelineFilters(timeline);

            return;
        }

        timeline.dataset.timelineBound = 'true';
        bindTimelineFilters(timeline);

        timeline.addEventListener('click', async (event) => {
            const button = event.target.closest('[data-timeline-load-more]');

            if (!button || !timeline.contains(button)) {
                return;
            }

            event.preventDefault();

            const loadMoreUrl = button.dataset.timelineLoadMoreUrl;
            const offset = Number.parseInt(button.dataset.timelineOffset ?? '0', 10);

            if (!loadMoreUrl || Number.isNaN(offset)) {
                return;
            }

            button.disabled = true;

            try {
                const requestUrl = `${loadMoreUrl}?offset=${offset}`;
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

                fragment.querySelectorAll('[data-timeline-group]').forEach((incomingGroup) => {
                    const bucket = incomingGroup.dataset.timelineGroup;
                    const existingGroup = list.querySelector(`[data-timeline-group="${bucket}"]`);

                    if (existingGroup) {
                        const itemsHost = existingGroup.querySelector('.unified-timeline-group-items');

                        incomingGroup.querySelectorAll('[data-timeline-event]').forEach((eventNode) => {
                            itemsHost?.appendChild(eventNode);
                        });

                        return;
                    }

                    list.appendChild(incomingGroup);
                });

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
                logTimelineFailure(`${loadMoreUrl}?offset=${offset}`, null, error);
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
