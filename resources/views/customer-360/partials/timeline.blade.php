<section class="customer-360-section" data-customer-360-section="timeline" aria-labelledby="customer-360-timeline-heading">
    @include('orders.workspace.partials.timeline', [
        'activityTimeline' => $timeline,
        'compact' => true,
        'showHeading' => true,
        'heading' => 'Recent Timeline',
        'emptyMessage' => 'No activity recorded yet.',
    ])
</section>
