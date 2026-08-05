@php
    use App\Enums\ServiceCaseSlaStatus;
    use App\Models\Order;
    use App\Support\AppDateFormatter;
    use App\Support\Customer360\Customer360AgentLanguagePresenter;

    $slaStatus = $serviceCase->slaStatus();
    $isCompleted = $order?->isTransactionLocked() ?? false;
    $commercialEnabled = is_array($commercialState ?? null);
    $durationLabel = match (true) {
        $isCompleted => Order::formatCompactDurationBetween($serviceCase->created_at, $order->completed_at),
        $slaStatus === ServiceCaseSlaStatus::Overdue => Customer360AgentLanguagePresenter::responseOverdueCompact($serviceCase->created_at),
        default => Order::formatCompactDurationBetween($serviceCase->created_at),
    };
    $responseOverdueTooltip = $slaStatus === ServiceCaseSlaStatus::Overdue
        ? Customer360AgentLanguagePresenter::responseOverdueTooltip($serviceCase->created_at)
        : null;
@endphp

@if($order)
    <div @class([
        'dashboard-status-sla-compact',
        'dashboard-status-sla-compact--resolved' => $isCompleted && $commercialEnabled,
    ])>
        @if($isScheduledWorkspace)
            @if($scheduledAppointmentPresentation ?? null)
                @include('dashboard.partials.scheduled-appointment-status-pill', [
                    'badge' => $scheduledAppointmentPresentation,
                ])
            @else
                —
            @endif
        @elseif($isCompleted && $commercialEnabled)
            <span class="dashboard-status-sla-compact__resolved-label">
                @if($durationLabel)
                    Resolved in {{ $durationLabel }}
                @else
                    Resolved
                @endif
            </span>
            <span class="visually-hidden">{{ $order->completionStatus()->label() }}</span>
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
                  aria-label="{{ $responseOverdueTooltip ?? $slaStatus->label() }}"
                  @if($responseOverdueTooltip) title="{{ $responseOverdueTooltip }}" @endif
                  data-bs-toggle="tooltip"
                  data-dashboard-tooltip
                  data-bs-placement="top"
                  data-bs-custom-class="dashboard-premium-tooltip-wrapper"
                  tabindex="0">
                @if($durationLabel)
                    <span class="dashboard-status-sla-compact__timer" aria-hidden="true">⏱</span>
                    <span class="dashboard-status-sla-compact__duration">{{ $durationLabel }}</span>
                @endif
            </span>
            <template class="dashboard-tooltip-template">
                @include('dashboard.partials.premium-tooltip', [
                    'compact' => [
                        'datetime' => AppDateFormatter::datetime($serviceCase->created_at) ?? '—',
                        'pendingDuration' => $responseOverdueTooltip
                            ?? (Order::formatCompactDurationBetween($serviceCase->created_at) ?? '—'),
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
