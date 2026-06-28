@props(['webhookLog'])

@php
    $statusClass = match ($webhookLog->processing_status) {
        'received' => 'text-bg-secondary',
        'processed' => 'text-bg-success',
        'failed' => 'text-bg-danger',
        default => 'text-bg-light text-dark border',
    };
@endphp

<span @class(['badge', $statusClass, 'text-capitalize'])>
    {{ str_replace('_', ' ', $webhookLog->processing_status) }}
</span>
