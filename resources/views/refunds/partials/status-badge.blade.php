@props(['status'])

@php
    $tone = match ($status) {
        \App\Enums\RefundStatus::Pending => 'text-bg-warning',
        \App\Enums\RefundStatus::PendingExecution => 'text-bg-info',
        \App\Enums\RefundStatus::Completed, \App\Enums\RefundStatus::Closed, \App\Enums\RefundStatus::Approved => 'text-bg-success',
        \App\Enums\RefundStatus::Rejected => 'text-bg-danger',
        default => 'text-bg-secondary',
    };
@endphp

<span class="badge {{ $tone }}">
    {{ $status->label() }}
</span>
