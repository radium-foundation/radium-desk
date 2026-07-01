import { afterEach, describe, expect, it } from 'vitest';
import { applyTimelineFilter, initUnifiedTimeline, TIMELINE_FILTER_EMPTY_MESSAGES } from '../../resources/js/unified-timeline';

describe('unified timeline filters', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    const setupTimeline = () => {
        document.body.innerHTML = `
            <div data-unified-timeline>
                <div data-timeline-filters>
                    <button type="button" data-timeline-filter-chip="all" class="is-active">All</button>
                    <button type="button" data-timeline-filter-chip="whatsapp">WhatsApp</button>
                    <button type="button" data-timeline-filter-chip="payments">Payments</button>
                </div>
                <div data-timeline-filter-empty hidden class="d-none"></div>
                <template data-timeline-filter-empty-messages>${JSON.stringify(TIMELINE_FILTER_EMPTY_MESSAGES)}</template>
                <div data-timeline-list>
                    <section data-timeline-group="today">
                        <div class="unified-timeline-group-items">
                            <article data-timeline-event data-timeline-filter="whatsapp"></article>
                            <article data-timeline-event data-timeline-filter="payments"></article>
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

        applyTimelineFilter(timeline, 'whatsapp', TIMELINE_FILTER_EMPTY_MESSAGES);

        const events = timeline.querySelectorAll('[data-timeline-event]');
        expect(events[0].hidden).toBe(false);
        expect(events[1].hidden).toBe(true);
    });

    it('shows filter-specific empty state when no events match', () => {
        const timeline = setupTimeline();
        const emptyState = timeline.querySelector('[data-timeline-filter-empty]');

        applyTimelineFilter(timeline, 'notes', TIMELINE_FILTER_EMPTY_MESSAGES);

        expect(emptyState.hidden).toBe(false);
        expect(emptyState.textContent).toBe('No Notes');
        expect(timeline.querySelector('[data-timeline-list]').hidden).toBe(true);
    });

    it('binds filter chips through initUnifiedTimeline', () => {
        const timeline = setupTimeline();

        initUnifiedTimeline(document);

        timeline.querySelector('[data-timeline-filter-chip="payments"]')?.click();

        const events = timeline.querySelectorAll('[data-timeline-event]');
        expect(events[0].hidden).toBe(true);
        expect(events[1].hidden).toBe(false);
    });
});
