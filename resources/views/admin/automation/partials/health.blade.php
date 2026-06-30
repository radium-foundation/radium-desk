@props([
    'counts' => [],
])

@php
    $cards = [
        ['label' => 'Automation Pending', 'key' => 'automation_pending', 'icon' => 'bi-hourglass-split', 'color' => 'warning'],
        ['label' => 'Waiting for Customer Serial', 'key' => 'waiting_for_customer_serial', 'icon' => 'bi-keyboard', 'color' => 'info'],
        ['label' => 'Validation Failed', 'key' => 'validation_failed', 'icon' => 'bi-exclamation-circle', 'color' => 'danger'],
        ['label' => 'Waiting RadiumBox', 'key' => 'radiumbox_pending', 'icon' => 'bi-cloud-arrow-down', 'color' => 'secondary'],
        ['label' => 'Assigned to Agent', 'key' => 'assigned_to_agent', 'icon' => 'bi-person-check', 'color' => 'primary'],
        ['label' => 'Assigned to Admin', 'key' => 'assigned_to_admin', 'icon' => 'bi-shield-check', 'color' => 'primary'],
        ['label' => 'Unassigned', 'key' => 'unassigned', 'icon' => 'bi-person-dash', 'color' => 'secondary'],
        ['label' => 'Grace Expired', 'key' => 'grace_expired', 'icon' => 'bi-clock-history', 'color' => 'danger'],
    ];
@endphp

<section class="mb-4" aria-labelledby="automation-health-heading">
    <h2 id="automation-health-heading" class="h5 mb-3">Automation Health</h2>
    <div class="dashboard-kpi-strip dashboard-kpi-strip--admin" role="region" aria-label="Automation health counts">
        @foreach($cards as $card)
            @include('dashboard.partials.kpi-strip-item', [
                'label' => $card['label'],
                'value' => $counts[$card['key']] ?? 0,
                'icon' => $card['icon'],
                'color' => $card['color'],
            ])
        @endforeach
    </div>
</section>
