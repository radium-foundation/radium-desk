@props([
    'incident',
    'order' => null,
    'device' => [],
    'healthCard' => [],
    'isWaitingForCustomer' => false,
    'waitingStateCard' => null,
])

@php
    use App\Enums\ServiceCaseSlaStatus;

    $slaStatus = $incident->slaStatus();

    $statusLabel = ($isWaitingForCustomer || is_array($waitingStateCard))
        ? 'Waiting Customer'
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
        ServiceCaseSlaStatus::Overdue => 'SLA Breached',
        ServiceCaseSlaStatus::Warning => 'SLA Warning',
        ServiceCaseSlaStatus::Paused => 'SLA Paused',
        default => 'Within SLA',
    };

    $product = $order !== null && filled($order->product_name ?? null)
        ? $order->product_name
        : ($device['model_short'] ?? $device['model_canonical'] ?? null);

    $assignedAgent = filled($incident->assignee?->name)
        ? $incident->assignee->name
        : null;
@endphp

<div {{ $attributes->merge(['class' => 'c360-ops-status-bar']) }}
     data-c360-ops-status-bar
     role="region"
     aria-label="Pinned case status">
    <div class="c360-ops-status-bar-track">
        <x-c360.chip
            :value="$incident->display_reference"
            variant="neutral"
            class="c360-chip--compact c360-ops-status-bar-chip c360-ops-status-bar-chip--sc"
            data-c360-status-field="sc"
        />
        <x-c360.chip
            :value="$statusLabel"
            :variant="$statusVariant"
            class="c360-chip--compact c360-ops-status-bar-chip"
            data-c360-status-field="status"
        />
        <x-c360.chip
            :value="$slaLabel"
            :variant="$slaVariant"
            class="c360-chip--compact c360-ops-status-bar-chip"
            data-c360-status-field="sla"
        />
        @if(filled($assignedAgent))
            <x-c360.chip
                :value="$assignedAgent"
                variant="info"
                icon="bi-person"
                class="c360-chip--compact c360-ops-status-bar-chip"
                data-c360-status-field="agent"
            />
        @else
            <x-c360.chip
                value="Unassigned"
                variant="neutral"
                icon="bi-person-dash"
                class="c360-chip--compact c360-ops-status-bar-chip"
                data-c360-status-field="agent"
            />
        @endif
        @if(filled($product))
            <x-c360.chip
                :value="$product"
                variant="neutral"
                class="c360-chip--compact c360-ops-status-bar-chip c360-ops-status-bar-chip--product"
                data-c360-status-field="product"
            />
        @endif
        @if($order !== null && filled($order->order_id ?? null))
            <x-c360.chip
                :value="$order->order_id"
                variant="neutral"
                class="c360-chip--compact c360-ops-status-bar-chip font-monospace"
                data-c360-status-field="order"
            />
        @endif
    </div>
</div>
