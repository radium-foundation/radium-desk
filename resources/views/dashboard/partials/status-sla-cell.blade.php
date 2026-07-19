@php
    use App\Enums\ServiceCaseSlaStatus;
    use App\Models\Order;
    use App\Support\AppDateFormatter;

    $slaStatus = $serviceCase->slaStatus();
    $isCompleted = $order?->isTransactionLocked() ?? false;
    $durationLabel = match (true) {
        $isCompleted => Order::formatCompactDurationBetween($serviceCase->created_at, $order->completed_at),
        $slaStatus === ServiceCaseSlaStatus::Overdue => 'Over',
        default => Order::formatCompactDurationBetween($serviceCase->created_at),
    };
@endphp

@if($order)
    <div class="dashboard-status-sla-compact">
        @if($isScheduledWorkspace)
            @if($scheduledAppointmentPresentation ?? null)
                @include('dashboard.partials.scheduled-appointment-status-pill', [
                    'badge' => $scheduledAppointmentPresentation,
                ])
            @else
                —
            @endif
        @elseif($isCompleted)
            <span class="dashboard-status-sla-compact__icon"
                  aria-hidden="true">✓</span>
            @if($durationLabel)
                <span class="dashboard-status-sla-compact__timer" aria-hidden="true">⏱</span>
                <span class="dashboard-status-sla-compact__duration">{{ $durationLabel }}</span>
            @endif
            <span class="visually-hidden">{{ $order->completionStatus()->label() }}</span>
        @else
            <span class="sla-status sla-status--compact {{ $slaStatus->cssClass() }}"
                  aria-label="{{ $slaStatus->label() }}"
                  data-bs-toggle="tooltip"
                  data-dashboard-tooltip
                  data-bs-placement="top"
                  data-bs-custom-class="dashboard-premium-tooltip-wrapper"
                  tabindex="0">
                <span class="sla-status-indicator" aria-hidden="true">{{ $slaStatus->indicator() }}</span>
                @if($durationLabel)
                    <span class="dashboard-status-sla-compact__timer" aria-hidden="true">⏱</span>
                    <span class="dashboard-status-sla-compact__duration">{{ $durationLabel }}</span>
                @endif
            </span>
            <template class="dashboard-tooltip-template">
                @include('dashboard.partials.premium-tooltip', [
                    'compact' => [
                        'datetime' => AppDateFormatter::datetime($serviceCase->created_at) ?? '—',
                        'pendingDuration' => Order::formatCompactDurationBetween($serviceCase->created_at) ?? '—',
                        'durationClass' => $slaStatus->tooltipDurationClass(),
                    ],
                ])
            </template>
            <span class="visually-hidden">{{ $order->completionStatus()->label() }}</span>
        @endif
    </div>
@else
    —
@endif
