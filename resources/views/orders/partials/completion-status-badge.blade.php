@props(['order', 'iconOnly' => false, 'dashboardTooltip' => false, 'tooltipAriaLabel' => null])

@php
    $status = $order->completionStatus();
@endphp

@if($status === \App\Enums\OrderCompletionStatus::PendingAdmin)
    <span @class([
            'badge text-bg-warning' => ! $iconOnly,
            'dashboard-completion-status-icon dashboard-completion-status-icon--pending' => $iconOnly,
        ])
          @if($dashboardTooltip && $iconOnly)
              aria-label="{{ $tooltipAriaLabel }}"
              tabindex="0"
              data-bs-toggle="tooltip"
              data-dashboard-tooltip
              data-bs-placement="top"
              data-bs-custom-class="dashboard-premium-tooltip-wrapper"
          @elseif(! $iconOnly)
              data-bs-toggle="tooltip"
              data-bs-placement="top"
              data-bs-title="Pending Admin"
              aria-label="Pending Admin"
          @else
              aria-label="Pending Admin"
          @endif>
        @if($iconOnly)
            <span aria-hidden="true">P</span>
        @else
            P
        @endif
    </span>
@else
    @if($iconOnly)
        <span class="dashboard-completion-status-icon dashboard-completion-status-icon--completed"
              @if($dashboardTooltip)
                  aria-label="{{ $tooltipAriaLabel }}"
                  tabindex="0"
                  data-bs-toggle="tooltip"
                  data-dashboard-tooltip
                  data-bs-placement="top"
                  data-bs-custom-class="dashboard-premium-tooltip-wrapper"
              @else
                  aria-label="{{ $status->label() }}"
              @endif>
            <i class="bi bi-check-circle-fill text-success" aria-hidden="true"></i>
        </span>
    @else
        <span class="badge text-bg-success">
            {{ $status->label() }}
        </span>
    @endif
@endif
