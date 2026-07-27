@php
    $businessTimeline = $businessTimeline ?? false;
    $timelineQuery = $timelineQuery ?? null;
@endphp

<div class="customer-360-activity-panel"
     data-customer-360-activity-panel
     data-customer-360-timeline-section
     data-timeline-refresh-url="{{ $timelineRefreshUrl ?? $loadMoreUrl }}">
    <x-c360.activity-panel
        :viewModel="$viewModel"
        heading="Timeline"
        :showFilters="true"
        :loadMoreUrl="$loadMoreUrl ?? null"
        :businessTimeline="$businessTimeline"
        :timelineQuery="$timelineQuery"
        emptyMessage="No customer activity recorded yet."
    />
</div>
