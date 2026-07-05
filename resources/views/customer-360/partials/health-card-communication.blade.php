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
    <span class="timeline-status-chip timeline-status-chip--sent">{{ $statusLabel }}</span>
    @if(filled($lastSentLabel) && $lastSentAt !== null)
        <time class="customer-360-health-meta"
              datetime="{{ $lastSentAt->toIso8601String() }}"
              title="{{ AppDateFormatter::timelineDatetime($lastSentAt) }}">
            {{ $lastSentLabel }}
        </time>
    @endif
@elseif($status === 'failed')
    <span class="timeline-status-chip timeline-status-chip--failed">{{ $statusLabel }}</span>
@else
    Not available
@endif
