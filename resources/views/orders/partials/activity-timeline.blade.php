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
                                {{ display_app_timeline_datetime($entry->occurredAt) }}
                            </div>
                            @if($entry->actor->isVisible())
                                <div class="activity-timeline-actor fw-semibold">
                                    <x-timeline-actor :actor="$entry->actor" />
                                </div>
                            @endif
                            <div class="fw-semibold">{{ $entry->title }}</div>
                            @if($entry->correctionChanges !== [])
                                <div class="activity-timeline-correction">
                                    @foreach($entry->correctionChanges as $change)
                                        <div class="activity-timeline-correction-field">
                                            <div class="activity-timeline-correction-field-label">{{ $change->label }}</div>
                                            <div class="activity-timeline-correction-field-value">{{ $change->previous }} → {{ $change->next }}</div>
                                        </div>
                                    @endforeach
                                    @if($entry->correctionReason)
                                        <div class="activity-timeline-correction-reason">
                                            <div class="activity-timeline-correction-field-label">Reason</div>
                                            <div class="activity-timeline-correction-field-value">{{ $entry->correctionReason }}</div>
                                        </div>
                                    @endif
                                </div>
                            @elseif($entry->detail)
                                <div class="text-muted small">{{ $entry->detail }}</div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
