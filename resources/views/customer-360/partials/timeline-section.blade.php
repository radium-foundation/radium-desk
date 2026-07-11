<div class="customer-360-activity-panel"
     data-customer-360-activity-panel
     data-customer-360-timeline-section
     data-timeline-refresh-url="{{ $timelineRefreshUrl ?? $loadMoreUrl }}">
    <x-c360.activity-panel
        :viewModel="$viewModel"
        heading="Activity"
        :showFilters="true"
        :loadMoreUrl="$loadMoreUrl ?? null"
        emptyMessage="No customer activity recorded yet."
    />
</div>
