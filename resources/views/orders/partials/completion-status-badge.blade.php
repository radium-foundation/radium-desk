@props(['order'])

@php
    $status = $order->completionStatus();
@endphp

<span @class([
    'badge',
    'text-bg-warning' => $status === \App\Enums\OrderCompletionStatus::PendingAdmin,
    'text-bg-success' => $status === \App\Enums\OrderCompletionStatus::Completed,
])>
    {{ $status->label() }}
</span>
