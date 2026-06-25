@props([
    'activityTimeline',
])

<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white py-3">
        <h2 class="h6 mb-0">Activity Timeline</h2>
    </div>
    <div class="card-body">
        @if($activityTimeline->isEmpty())
            <div class="text-center text-muted py-4">
                <i class="bi bi-clock-history fs-4 d-block mb-2"></i>
                <p class="mb-0">No activity recorded yet.</p>
            </div>
        @else
            <div class="activity-timeline">
                @foreach($activityTimeline as $entry)
                    <div class="activity-timeline-item">
                        <div class="activity-timeline-marker"></div>
                        <div class="activity-timeline-content">
                            <div class="activity-timeline-datetime">
                                {{ $entry->occurredAt->format('d M Y') }}<br>
                                {{ $entry->occurredAt->format('h:i A') }}
                            </div>
                            <div class="fw-semibold">{{ $entry->title }}</div>
                            @if($entry->detail)
                                <div class="text-muted small">{{ $entry->detail }}</div>
                            @endif
                            @if($entry->actorName)
                                <div class="text-muted small">by {{ $entry->actorName }}</div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
