@props([
    'communication' => [],
])

@php
    $status = $communication['status'] ?? 'not_sent';
    $statusLabel = $communication['status_label'] ?? 'NOT SENT';
@endphp

<div class="request-serial-dialog-channel-communication" data-request-serial-communication="{{ $status }}">
    <span @class([
        'request-serial-dialog-comm-status',
        'request-serial-dialog-comm-status--sent' => $status === 'sent',
        'request-serial-dialog-comm-status--failed' => $status === 'failed',
        'request-serial-dialog-comm-status--not-sent' => $status === 'not_sent',
    ])>
        {{ $statusLabel }}
    </span>

    @if($status === 'sent' && filled($communication['last_sent_label'] ?? null))
        <div class="request-serial-dialog-channel-note">
            Last sent: {{ $communication['last_sent_label'] }}
        </div>
    @endif

    @if($status === 'failed' && filled($communication['failure_reason'] ?? null))
        <div class="request-serial-dialog-channel-reason">
            <strong>Reason:</strong> {{ $communication['failure_reason'] }}
        </div>
    @endif
</div>
