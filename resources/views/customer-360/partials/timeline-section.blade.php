<div class="customer-360-timeline-section"
     data-customer-360-timeline-section
     data-timeline-refresh-url="{{ $timelineRefreshUrl ?? $loadMoreUrl }}">
    <x-timeline-renderer
        :viewModel="$viewModel"
        heading="Customer Timeline"
        :compact="true"
        :showFilters="true"
        :loadMoreUrl="$loadMoreUrl ?? null"
        emptyMessage="No customer activity recorded yet."
    />
</div>
