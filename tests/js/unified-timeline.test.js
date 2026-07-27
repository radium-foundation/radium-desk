import { afterEach, describe, expect, it, vi } from 'vitest';
import { applyTimelineFilter, initUnifiedTimeline, TIMELINE_FILTER_EMPTY_MESSAGES } from '../../resources/js/unified-timeline';

describe('unified timeline filters', () => {
    afterEach(() => {
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
    });

    const setupTimeline = () => {
        document.body.innerHTML = `
            <div data-unified-timeline>
                <div data-timeline-filters>
                    <button type="button" data-timeline-filter-chip="all" class="is-active">All</button>
                    <button type="button" data-timeline-filter-chip="notifications">Notifications</button>
                    <button type="button" data-timeline-filter-chip="payments">Payments</button>
                </div>
                <div data-timeline-filter-empty hidden class="d-none"></div>
                <template data-timeline-filter-empty-messages>${JSON.stringify(TIMELINE_FILTER_EMPTY_MESSAGES)}</template>
                <div data-timeline-list>
                    <section data-timeline-group="today">
                        <div class="unified-timeline-group-items">
                            <article data-timeline-event data-timeline-milestone data-timeline-filter="notifications,system">
                                <article data-timeline-raw-event></article>
                            </article>
                            <article data-timeline-event data-timeline-milestone data-timeline-filter="payments,support"></article>
                        </div>
                    </section>
                </div>
                <div data-timeline-load-more-wrap></div>
            </div>
        `;

        return document.querySelector('[data-unified-timeline]');
    };

    it('hides non-matching events for a selected filter', () => {
        const timeline = setupTimeline();

        applyTimelineFilter(timeline, 'notifications', TIMELINE_FILTER_EMPTY_MESSAGES);

        const events = timeline.querySelectorAll('[data-timeline-event]');
        expect(events[0].hidden).toBe(false);
        expect(events[1].hidden).toBe(true);
    });

    it('does not count nested raw events when filtering', () => {
        const timeline = setupTimeline();

        applyTimelineFilter(timeline, 'notifications', TIMELINE_FILTER_EMPTY_MESSAGES);

        const raw = timeline.querySelector('[data-timeline-raw-event]');
        expect(raw).not.toBeNull();
        expect(timeline.querySelectorAll('[data-timeline-event]').length).toBe(2);
    });

    it('shows filter-specific empty state when no events match', () => {
        const timeline = setupTimeline();
        const emptyState = timeline.querySelector('[data-timeline-filter-empty]');

        applyTimelineFilter(timeline, 'customer', TIMELINE_FILTER_EMPTY_MESSAGES);

        expect(emptyState.hidden).toBe(false);
        expect(emptyState.textContent).toBe('No customer events');
        expect(timeline.querySelector('[data-timeline-list]').hidden).toBe(true);
    });

    it('binds filter chips through initUnifiedTimeline', () => {
        const timeline = setupTimeline();

        initUnifiedTimeline(document.body);

        timeline.querySelector('[data-timeline-filter-chip="payments"]')?.click();

        const events = timeline.querySelectorAll('[data-timeline-event]');
        expect(events[0].hidden).toBe(true);
        expect(events[1].hidden).toBe(false);
    });

    it('appends load-more milestones without extracting nested raw events', async () => {
        document.body.innerHTML = `
            <div data-unified-timeline data-timeline-base-url="/timeline">
                <div data-timeline-list>
                    <section data-timeline-group="earlier">
                        <div class="unified-timeline-group-items">
                            <article data-timeline-event data-timeline-milestone data-timeline-filter="system"></article>
                        </div>
                    </section>
                </div>
                <div data-timeline-load-more-wrap>
                    <button type="button"
                            data-timeline-load-more
                            data-timeline-load-more-url="/timeline"
                            data-timeline-offset="1"
                            data-timeline-query="serial"></button>
                </div>
            </div>
        `;

        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            text: async () => `
                <section data-timeline-group="earlier">
                    <div class="unified-timeline-group-items">
                        <article data-timeline-event data-timeline-milestone data-timeline-filter="notifications">
                            <article data-timeline-raw-event></article>
                        </article>
                    </div>
                </section>
                <div data-timeline-load-more-wrap hidden></div>
            `,
        });
        vi.stubGlobal('fetch', fetchMock);

        initUnifiedTimeline(document.body);
        document.querySelector('[data-timeline-load-more]')?.click();

        await vi.waitFor(() => {
            expect(fetchMock).toHaveBeenCalled();
        });

        const requestUrl = String(fetchMock.mock.calls[0][0]);
        expect(requestUrl).toContain('offset=1');
        expect(requestUrl).toContain('q=serial');

        await vi.waitFor(() => {
            expect(document.querySelectorAll('[data-timeline-milestone]').length).toBe(2);
        });

        expect(document.querySelectorAll('[data-timeline-raw-event]').length).toBe(1);
    });
});
