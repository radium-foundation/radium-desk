@props([
    'activityTimeline',
    'incident',
])

@php
    use App\Data\ServiceCaseTimelineEntry;
    use App\Services\RemarkMentionFormatter;

    $mentionFormatter = app(RemarkMentionFormatter::class);
    $currentUserId = auth()->id();
@endphp

<div class="card border-0 shadow-sm mb-3" id="activity-timeline">
    <div class="card-header bg-white py-3">
        <h2 class="h6 mb-0">{{ config('ui.service_case.activity_timeline_heading') }}</h2>
    </div>
    <div class="card-body service-case-timeline-body">
        @if($activityTimeline->isEmpty())
            <div class="text-center text-muted py-4">
                <i class="bi bi-clock-history fs-4 d-block mb-2"></i>
                <p class="mb-0">No activity recorded yet.</p>
            </div>
        @else
            <div class="service-case-activity-feed">
                @foreach($activityTimeline as $entry)
                    @php
                        $isRemark = $entry->type === ServiceCaseTimelineEntry::TYPE_REMARK;
                        $isOwnRemark = $isRemark && $entry->remark?->user_id === $currentUserId;
                    @endphp
                    <div @class([
                        'service-case-activity-item',
                        'service-case-activity-item--remark' => $isRemark,
                        'service-case-activity-item--own' => $isOwnRemark,
                    ])>
                        @if(! $isRemark)
                            <div class="service-case-activity-meta">
                                <span class="service-case-activity-time">{{ display_app_timeline_time($entry->occurredAt) }}</span>
                                @if($entry->actor->isVisible())
                                    <span class="service-case-activity-actor fw-semibold">
                                        <x-timeline-actor :actor="$entry->actor" />
                                    </span>
                                @endif
                            </div>
                        @endif
                        <div @class([
                            'service-case-activity-bubble',
                            'service-case-activity-bubble--system' => ! $isRemark,
                            'service-case-activity-bubble--remark' => $isRemark,
                        ])>
                            @if($isRemark)
                                <div class="service-case-activity-title">📝 {{ $entry->title ?: 'Internal Note' }}</div>
                                <div class="service-case-activity-message">{!! $mentionFormatter->format($entry->body ?? '') !!}</div>
                                <div class="service-case-activity-note-meta small text-muted mt-2">
                                    @if($entry->actor->isVisible())
                                        <span class="service-case-activity-note-by">
                                            By: <x-timeline-actor :actor="$entry->actor" />
                                        </span>
                                    @endif
                                    <span class="service-case-activity-note-time">{{ display_app_timeline_datetime($entry->occurredAt) }}</span>
                                </div>
                                @if($entry->remark && $entry->remark->mentionedUserNames() !== [])
                                    <div class="service-case-activity-note-mentions small text-muted mt-1">
                                        Mentioned: {{ implode(', ', $entry->remark->mentionedUserNames()) }}
                                    </div>
                                @endif
                                @if($entry->remark)
                                    @can('delete', $entry->remark)
                                        <form method="POST" action="{{ route('remarks.destroy', $entry->remark) }}"
                                              class="service-case-activity-delete"
                                              onsubmit="return confirm('Delete this note?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-link text-danger p-0" title="Delete note">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    @endcan
                                @endif
                            @else
                                <div class="service-case-activity-title">{{ $entry->title }}</div>
                                @if(filled($entry->body))
                                    <div class="service-case-activity-detail small text-muted mt-1">{!! nl2br(e($entry->body)) !!}</div>
                                @endif
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>

@once
    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const feed = document.querySelector('.service-case-activity-feed');

                if (!feed) {
                    return;
                }

                const lastItem = feed.lastElementChild;

                if (lastItem) {
                    lastItem.scrollIntoView({ block: 'nearest', behavior: 'instant' in window ? 'instant' : 'auto' });
                }
            });
        </script>
    @endpush
@endonce
