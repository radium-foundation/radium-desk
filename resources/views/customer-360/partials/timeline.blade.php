<section class="customer-360-section" data-customer-360-section="timeline" aria-labelledby="customer-360-timeline-heading">
    <x-timeline-renderer
        :viewModel="$timeline"
        heading="Customer Timeline"
        :compact="true"
        :loadMoreUrl="$timelineLoadMoreUrl ?? null"
        emptyMessage="No customer activity recorded yet."
    />
</section>
