@props([
    'incident',
    'order',
    'device' => [],
    'healthCard' => [],
    'summary' => [],
    'isWaitingForCustomer' => false,
    'waitingStateCard' => null,
])

@php
    use App\Enums\ServiceCaseSlaStatus;

    $slaStatus = $incident->slaStatus();
    $openCases = (int) ($summary['open_cases'] ?? 0);
    $repeatContact = is_array($healthCard['repeat_contact'] ?? null) ? $healthCard['repeat_contact'] : [];
    $highUrgency = (bool) ($repeatContact['high_urgency'] ?? false);
    $contactsToday = (int) ($repeatContact['total_today'] ?? 0);

    $healthStatus = match (true) {
        $highUrgency => ['status' => 'critical', 'label' => 'Critical'],
        $openCases > 0 || $contactsToday > 0 => ['status' => 'attention', 'label' => 'Needs attention'],
        default => ['status' => 'healthy', 'label' => 'Healthy'],
    };

    $statusLabel = ($isWaitingForCustomer || is_array($waitingStateCard))
        ? 'Waiting customer'
        : $incident->status->label();

    $statusVariant = ($isWaitingForCustomer || is_array($waitingStateCard))
        ? 'waiting'
        : match ($incident->status->value) {
            'open' => 'warning',
            'in_progress' => 'info',
            'awaiting_product_details' => 'primary',
            'resolved' => 'success',
            'closed' => 'neutral',
            default => 'neutral',
        };

    $slaVariant = match ($slaStatus) {
        ServiceCaseSlaStatus::Overdue => 'danger',
        ServiceCaseSlaStatus::Warning => 'warning',
        ServiceCaseSlaStatus::Paused => 'neutral',
        default => 'success',
    };

    $slaLabel = match ($slaStatus) {
        ServiceCaseSlaStatus::Overdue => 'SLA breached',
        ServiceCaseSlaStatus::Warning => 'SLA warning',
        ServiceCaseSlaStatus::Paused => 'SLA paused',
        default => 'Within SLA',
    };

    $product = $order !== null && filled($order->product_name ?? null)
        ? $order->product_name
        : ($device['model_short'] ?? $device['model_canonical'] ?? null);

    $serial = $device['serial_number'] ?? ($order->serial_number ?? null);
    $customerName = filled($healthCard['name'] ?? null)
        ? $healthCard['name']
        : ($order->customer_name ?? null);
    $assignedAgent = filled($incident->assignee?->name)
        ? $incident->assignee->name
        : null;
@endphp

<header {{ $attributes->merge(['class' => 'c360-ops-header']) }}
        data-customer-360-section="operations-header"
        aria-label="Service case overview">
    <div class="c360-ops-header-primary">
        <span class="c360-ops-header-sc font-monospace">{{ $incident->display_reference }}</span>
        <x-c360.chip :value="$statusLabel" :variant="$statusVariant" class="c360-chip--status" />
        @if($incident->high_priority)
            <x-c360.chip value="High priority" variant="danger" icon="bi-flag-fill" />
        @endif
        <x-c360.chip :value="$slaLabel" :variant="$slaVariant" />
        <x-c360.chip :value="$healthStatus['label']" :variant="$healthStatus['status']" class="c360-chip--health" />
    </div>

    <div class="c360-ops-header-meta" role="list" aria-label="Case identifiers">
        @if(filled($product))
            <span class="c360-ops-header-meta-item" role="listitem">
                <span class="c360-ops-header-meta-label">Product</span>
                <span class="c360-ops-header-meta-value">{{ $product }}</span>
            </span>
        @endif
        @if($order !== null && filled($order->order_id ?? null))
            <span class="c360-ops-header-meta-item" role="listitem">
                <span class="c360-ops-header-meta-label">Order</span>
                <span class="c360-ops-header-meta-value font-monospace">{{ $order->order_id }}</span>
            </span>
        @endif
        @if(filled($serial))
            <span class="c360-ops-header-meta-item" role="listitem">
                <span class="c360-ops-header-meta-label">Serial</span>
                <span class="c360-ops-header-meta-value font-monospace">{{ $serial }}</span>
            </span>
        @endif
        @if(filled($customerName))
            <span class="c360-ops-header-meta-item c360-ops-header-meta-item--name" role="listitem">
                <span class="c360-ops-header-meta-label">Customer</span>
                <span class="c360-ops-header-meta-value">{{ $customerName }}</span>
            </span>
        @endif
        <span class="c360-ops-header-meta-item" role="listitem">
            <span class="c360-ops-header-meta-label">Assigned agent</span>
            <span class="c360-ops-header-meta-value">{{ $assignedAgent ?? 'Unassigned' }}</span>
        </span>
    </div>
</header>
