@props(['order'])

@php
    $status = $order->completionStatus();
@endphp

@if($status === \App\Enums\OrderCompletionStatus::PendingAdmin)
    <span class="badge text-bg-warning"
          data-bs-toggle="tooltip"
          data-bs-placement="top"
          data-bs-title="Pending Admin"
          aria-label="Pending Admin">P</span>
@else
    <span class="badge text-bg-success">
        {{ $status->label() }}
    </span>
@endif
