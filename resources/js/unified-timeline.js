export const initUnifiedTimeline = (root = document) => {
    const timelines = root.querySelectorAll('[data-unified-timeline]');

    timelines.forEach((timeline) => {
        if (timeline.dataset.timelineBound === 'true') {
            return;
        }

        timeline.dataset.timelineBound = 'true';

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
                const response = await fetch(`${loadMoreUrl}?offset=${offset}`, {
                    headers: {
                        Accept: 'text/html',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) {
                    return;
                }

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
            } catch (error) {
                // Ignore transient network errors during timeline pagination.
            } finally {
                const activeButton = timeline.querySelector('[data-timeline-load-more]');

                if (activeButton) {
                    activeButton.disabled = false;
                }
            }
        });
    });
};
