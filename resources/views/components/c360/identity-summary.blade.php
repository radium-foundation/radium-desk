@props([
    'order' => null,
    'incident' => null,
    'workspaceContext' => null,
    'showSerialAction' => true,
    'canCorrectSerialNumber' => false,
    'variant' => 'card',
])

@php
    $serialNumber = filled($order?->serial_number) ? trim((string) $order->serial_number) : null;
    $orderId = $order?->order_id;
    $productName = $order?->displayDeviceModelName();
    $orderDate = $order?->displayOrderDate();
    $lastUpdated = $order?->updated_at;
    $lastUpdatedBy = $order?->updater?->name;
    $isCompactSidebar = $variant === 'compact-sidebar';
    $isSidebar = $variant === 'sidebar' || $isCompactSidebar;
@endphp

<section {{ $attributes->merge([
    'class' => 'c360-dialog-identity'
        .($isSidebar ? ' c360-dialog-identity--sidebar' : '')
        .($isCompactSidebar ? ' c360-dialog-identity--compact-sidebar' : ''),
]) }}
         aria-labelledby="c360-identity-summary-heading">
    <h3 class="visually-hidden" id="c360-identity-summary-heading">Identity Summary</h3>

    <dl class="c360-dialog-identity-dl">
        <div class="c360-dialog-identity-row">
            <dt><span aria-hidden="true">📦</span> Order ID</dt>
            <dd class="c360-dialog-identity-value">
                <span class="font-monospace fw-semibold">{{ filled($orderId) ? $orderId : '—' }}</span>
                @if(filled($orderId))
                    <x-c360.copy-button
                        :value="$orderId"
                        label="Order ID"
                        toast="Order ID copied" />
                @endif
            </dd>
        </div>

        <div class="c360-dialog-identity-row">
            <dt><span aria-hidden="true">🔢</span> Serial</dt>
            <dd class="c360-dialog-identity-value">
                @if($serialNumber !== null)
                    <span class="font-monospace fw-semibold">{{ $serialNumber }}</span>
                    <x-c360.copy-button
                        :value="$serialNumber"
                        label="Serial"
                        toast="Serial copied" />
                @else
                    <span class="c360-dialog-serial-missing">
                        <span aria-hidden="true">⚠</span> No Serial Assigned
                    </span>
                @endif
            </dd>
        </div>

        <div class="c360-dialog-identity-row">
            <dt><span aria-hidden="true">📱</span> Product</dt>
            <dd>{{ filled($productName) ? $productName : '—' }}</dd>
        </div>

        @if($orderDate !== null)
            <div class="c360-dialog-identity-row">
                <dt><span aria-hidden="true">📅</span> Order Date</dt>
                <dd>{{ display_app_date($orderDate) }}</dd>
            </div>
        @endif

        @if($lastUpdated !== null)
            <div class="c360-dialog-identity-row">
                <dt><span aria-hidden="true">🕒</span> Last Updated</dt>
                <dd>{{ display_app_date($lastUpdated) }}</dd>
            </div>
        @endif

        <div class="c360-dialog-identity-row">
            <dt><span aria-hidden="true">👤</span> Updated By</dt>
            <dd>{{ filled($lastUpdatedBy) ? $lastUpdatedBy : '—' }}</dd>
        </div>
    </dl>

    @if($showSerialAction && $serialNumber !== null && $incident !== null)
        <div class="c360-dialog-serial-action">
            <span class="c360-dialog-serial-action-label">Need to correct serial?</span>
            @if($canCorrectSerialNumber)
                <button type="button"
                        class="btn btn-sm c360-dialog-serial-action-btn{{ $isCompactSidebar ? ' c360-dialog-serial-action-btn--ghost' : ' btn-link p-0' }}"
                        data-workspace-trigger="correct-serial-number"
                        data-workspace-incident-id="{{ $incident->id }}"
                        data-workspace-context="{{ $workspaceContext }}">
                    Correct Serial Number
                </button>
            @else
                <button type="button"
                        class="btn btn-sm c360-dialog-serial-action-btn{{ $isCompactSidebar ? ' c360-dialog-serial-action-btn--ghost' : ' btn-link p-0' }}"
                        disabled
                        aria-disabled="true"
                        title="Serial correction is not available for your role or this case">
                    Correct Serial Number
                </button>
            @endif
        </div>
    @endif
</section>
