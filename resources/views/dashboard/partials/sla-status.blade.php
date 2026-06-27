@php
    use App\Models\Order;
    use App\Support\AppDateFormatter;

    $slaStatus = $serviceCase->slaStatus();
@endphp

<span class="sla-status {{ $slaStatus->cssClass() }}"
      aria-label="{{ $slaStatus->label() }}"
      data-bs-toggle="tooltip"
      data-dashboard-tooltip
      data-bs-placement="top"
      data-bs-custom-class="dashboard-premium-tooltip-wrapper">
    <span class="sla-status-indicator" aria-hidden="true">{{ $slaStatus->indicator() }}</span>
    <span class="sla-status-label">{{ $slaStatus->label() }}</span>
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
