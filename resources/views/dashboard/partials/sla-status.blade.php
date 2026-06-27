@php
    use App\Models\Order;
    use App\Support\AppDateFormatter;

    $slaStatus = $serviceCase->slaStatus();
    $tooltipHtml = view('dashboard.partials.premium-tooltip', [
        'sections' => [
            [
                'label' => 'Created',
                'value' => AppDateFormatter::datetime($serviceCase->created_at) ?? '—',
            ],
            [
                'label' => 'Pending',
                'value' => Order::formatDurationBetween($serviceCase->created_at) ?? '—',
            ],
            [
                'label' => 'SLA',
                'value' => $slaStatus->label(),
            ],
        ],
    ])->render();
@endphp

<span class="sla-status {{ $slaStatus->cssClass() }}"
      aria-label="{{ $slaStatus->label() }}"
      data-bs-toggle="tooltip"
      data-bs-placement="top"
      data-bs-html="true"
      data-bs-custom-class="dashboard-premium-tooltip-wrapper"
      data-bs-title="{{ e($tooltipHtml) }}">
    <span class="sla-status-indicator" aria-hidden="true">{{ $slaStatus->indicator() }}</span>
    <span class="sla-status-label">{{ $slaStatus->label() }}</span>
</span>
