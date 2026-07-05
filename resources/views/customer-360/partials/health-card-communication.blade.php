@props([
    'communication' => [],
])

@php
    use App\Support\AppDateFormatter;

    $status = $communication['status'] ?? 'not_sent';
    $statusLabel = $communication['status_label'] ?? 'NOT SENT';
    $lastSentAt = $communication['last_sent_at'] ?? null;
    $lastSentLabel = $communication['last_sent_label'] ?? null;
@endphp

@if($status === 'sent')
    <span class="customer-360-health-communication-inline">
        <span class="timeline-status-chip timeline-status-chip--sent">{{ $statusLabel }}</span><span class="customer-360-health-communication-separator" aria-hidden="true"> · </span>@if(filled($lastSentLabel) && $lastSentAt !== null)<time
            class="customer-360-health-meta"
            datetime="{{ $lastSentAt->toIso8601String() }}"
            title="{{ AppDateFormatter::timelineDatetime($lastSentAt) }}"
        >{{ $lastSentLabel }}</time>@endif
    </span>
@elseif($status === 'failed')
    <span class="timeline-status-chip timeline-status-chip--failed">{{ $statusLabel }}</span>
@else
    Not available
@endif
