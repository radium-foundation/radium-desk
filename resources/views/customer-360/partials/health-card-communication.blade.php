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
        <x-c360.status-banner variant="success" icon="✓">{{ $statusLabel }}</x-c360.status-banner>
        @if(filled($lastSentLabel) && $lastSentAt !== null)
            <time class="c360-customer-snapshot-meta"
                  datetime="{{ $lastSentAt->toIso8601String() }}"
                  title="{{ AppDateFormatter::timelineDatetime($lastSentAt) }}">
                {{ $lastSentLabel }}
            </time>
        @endif
    </span>
@elseif($status === 'failed')
    <x-c360.status-banner variant="danger" icon="✖">{{ $statusLabel }}</x-c360.status-banner>
@else
    <x-c360.unavailable-pill />
@endif
