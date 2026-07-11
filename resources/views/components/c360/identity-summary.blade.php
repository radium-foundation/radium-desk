@props([
    'order' => null,
    'incident' => null,
    'workspaceContext' => null,
    'showSerialAction' => true,
    'canCorrectSerialNumber' => false,
])

@php
    $serialNumber = filled($order?->serial_number) ? trim((string) $order->serial_number) : null;
    $productName = $order?->displayDeviceModelName();
    $orderDate = $order?->displayOrderDate();
    $lastUpdated = $order?->updated_at;
    $lastUpdatedBy = $order?->updater?->name;
@endphp

<section {{ $attributes->merge(['class' => 'c360-dialog-identity']) }}
         aria-labelledby="c360-identity-summary-heading">
    <h3 class="visually-hidden" id="c360-identity-summary-heading">Identity Summary</h3>

    <dl class="c360-dialog-identity-dl">
        <div class="c360-dialog-identity-row">
            <dt><span aria-hidden="true">📦</span> Order ID</dt>
            <dd class="font-monospace fw-semibold">{{ $order?->order_id ?? '—' }}</dd>
        </div>

        <div class="c360-dialog-identity-row">
            <dt><span aria-hidden="true">🔢</span> Serial Number</dt>
            <dd>
                @if($serialNumber !== null)
                    <span class="font-monospace fw-semibold">{{ $serialNumber }}</span>
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
            <dt><span aria-hidden="true">👤</span> Last Updated By</dt>
            <dd>{{ filled($lastUpdatedBy) ? $lastUpdatedBy : '—' }}</dd>
        </div>
    </dl>

    @if($showSerialAction && $serialNumber !== null && $incident !== null)
        <div class="c360-dialog-serial-action">
            <span class="c360-dialog-serial-action-label">Need to correct serial?</span>
            @if($canCorrectSerialNumber)
                <button type="button"
                        class="btn btn-link btn-sm p-0 c360-dialog-serial-action-btn"
                        data-workspace-trigger="correct-serial-number"
                        data-workspace-incident-id="{{ $incident->id }}"
                        data-workspace-context="{{ $workspaceContext }}">
                    Correct Serial Number
                </button>
            @else
                <button type="button"
                        class="btn btn-link btn-sm p-0 c360-dialog-serial-action-btn"
                        disabled
                        aria-disabled="true"
                        title="Serial correction is not available for your role or this case">
                    Correct Serial Number
                </button>
            @endif
        </div>
    @endif
</section>
