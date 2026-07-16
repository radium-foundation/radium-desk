@props(['order', 'iconOnly' => false])

@php
    $status = $order->completionStatus();
@endphp

@if($status === \App\Enums\OrderCompletionStatus::PendingAdmin)
    <span @class([
            'badge text-bg-warning' => ! $iconOnly,
            'dashboard-completion-status-icon dashboard-completion-status-icon--pending' => $iconOnly,
        ])
          @unless($iconOnly)
              data-bs-toggle="tooltip"
              data-bs-placement="top"
              data-bs-title="Pending Admin"
          @endunless
          aria-label="Pending Admin">
        @if($iconOnly)
            <span aria-hidden="true">P</span>
        @else
            P
        @endif
    </span>
@else
    @if($iconOnly)
        <span class="dashboard-completion-status-icon dashboard-completion-status-icon--completed"
              aria-label="{{ $status->label() }}">
            <i class="bi bi-check-circle-fill text-success" aria-hidden="true"></i>
        </span>
    @else
        <span class="badge text-bg-success">
            {{ $status->label() }}
        </span>
    @endif
@endif
