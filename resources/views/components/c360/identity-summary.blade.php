@props([
    'order' => null,
    'incident' => null,
    'workspaceContext' => null,
    'showSerialAction' => true,
    'canCorrectSerialNumber' => false,
    'showDeviceModelAction' => true,
    'canCorrectDeviceModel' => false,
    'canCorrectDeviceIdentity' => false,
    'variant' => 'sidebar',
])

@php
    $serialNumber = filled($order?->serial_number) ? trim((string) $order->serial_number) : null;
    $orderId = $order?->order_id;
    $productName = $order?->displayDeviceModelName();
    $orderDate = $order?->displayOrderDate();
    $lastUpdated = $order?->updated_at;
    $lastUpdatedBy = $order?->updater?->name;
    $isSidebar = $variant === 'sidebar';
@endphp

<section {{ $attributes->merge([
    'class' => 'c360-dialog-identity'.($isSidebar ? ' c360-dialog-identity--sidebar' : ''),
]) }}
         aria-labelledby="c360-identity-summary-heading">
    <h3 class="visually-hidden" id="c360-identity-summary-heading">Identity Summary</h3>

    <dl class="c360-dialog-identity-dl">
        <div class="c360-dialog-identity-row">
            <dt><span aria-hidden="true">📦</span> Order ID</dt>
            <dd>
                <span class="c360-dialog-identity-value-text font-monospace">{{ filled($orderId) ? $orderId : '—' }}</span>
            </dd>
        </div>

        <div class="c360-dialog-identity-row">
            <dt><span aria-hidden="true">🔢</span> Serial</dt>
            <dd>
                @if($serialNumber !== null)
                    <span class="c360-dialog-identity-value-text font-monospace">{{ $serialNumber }}</span>
                @else
                    <span class="c360-dialog-serial-missing">
                        <span aria-hidden="true">⚠</span> No Serial Assigned
                    </span>
                @endif
            </dd>
        </div>

        <div class="c360-dialog-identity-row">
            <dt><span aria-hidden="true">📱</span> Product</dt>
            <dd>
                <span class="c360-dialog-identity-value-text">{{ filled($productName) ? $productName : '—' }}</span>
            </dd>
        </div>

        @if($orderDate !== null)
            <div class="c360-dialog-identity-row">
                <dt><span aria-hidden="true">📅</span> Order Date</dt>
                <dd>
                    <span class="c360-dialog-identity-value-text">{{ display_app_date($orderDate) }}</span>
                </dd>
            </div>
        @endif

        @if($lastUpdated !== null)
            <div class="c360-dialog-identity-row">
                <dt><span aria-hidden="true">🕒</span> Last Updated</dt>
                <dd>
                    <span class="c360-dialog-identity-value-text">{{ display_app_date($lastUpdated) }}</span>
                </dd>
            </div>
        @endif

        <div class="c360-dialog-identity-row">
            <dt><span aria-hidden="true">👤</span> Updated By</dt>
            <dd>
                <span class="c360-dialog-identity-value-text">{{ filled($lastUpdatedBy) ? $lastUpdatedBy : '—' }}</span>
            </dd>
        </div>
    </dl>

    @if($incident !== null && ($canCorrectDeviceIdentity || (($showDeviceModelAction || $showSerialAction) && ($canCorrectDeviceModel || $canCorrectSerialNumber))))
        <div class="c360-dialog-serial-action">
            <span class="c360-dialog-serial-action-label">Need identity correction?</span>
            @if($canCorrectDeviceIdentity)
                <button type="button"
                        class="btn btn-sm c360-dialog-serial-action-btn c360-dialog-serial-action-btn--ghost"
                        data-workspace-trigger="correct-device-identity"
                        data-workspace-incident-id="{{ $incident->id }}"
                        data-workspace-context="{{ $workspaceContext }}">
                    Correct Device Identity
                </button>
            @elseif($showDeviceModelAction && $canCorrectDeviceModel)
                <button type="button"
                        class="btn btn-sm c360-dialog-serial-action-btn c360-dialog-serial-action-btn--ghost"
                        data-workspace-trigger="correct-device-model"
                        data-workspace-incident-id="{{ $incident->id }}"
                        data-workspace-context="{{ $workspaceContext }}">
                    Correct Device Model
                </button>
            @elseif($showSerialAction && $serialNumber !== null && $canCorrectSerialNumber)
                <button type="button"
                        class="btn btn-sm c360-dialog-serial-action-btn c360-dialog-serial-action-btn--ghost"
                        data-workspace-trigger="correct-serial-number"
                        data-workspace-incident-id="{{ $incident->id }}"
                        data-workspace-context="{{ $workspaceContext }}">
                    Correct Serial
                </button>
            @else
                <button type="button"
                        class="btn btn-sm c360-dialog-serial-action-btn c360-dialog-serial-action-btn--ghost"
                        disabled
                        aria-disabled="true"
                        title="Device identity correction is not available for your role or this case">
                    Correct Device Identity
                </button>
            @endif
        </div>
    @endif
</section>
