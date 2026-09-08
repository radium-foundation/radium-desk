@php
    $action = $row->nextAction;
    $subtitles = [
        'Allocate Serial' => 'Assign the verified physical device to this order.',
        'Issue Invoice' => 'Issue the hardware GST invoice for this one order.',
        'Get Courier Options' => 'Prepare shipment details, then fetch returned courier options.',
        'Select Courier' => 'Choose one courier returned by Shiprocket.',
        'Create Shipment' => 'Create the provider shipment for this prepared order.',
        'Reconcile Shipment' => 'Reconcile the existing shipment before continuing.',
        'Assign AWB' => 'Assign the AWB from the bound shipment.',
        'Generate Label' => 'Generate the shipping label for this shipment.',
        'Request Pickup' => 'Request pickup for the labeled shipment.',
        'Generate Manifest' => 'Generate the pickup manifest for this shipment.',
        'Upload Package Photo' => 'Add package photo evidence. This does not block operations.',
    ];
    $icons = [
        'Allocate Serial' => '🔢',
        'Issue Invoice' => '🧾',
        'Get Courier Options' => '🚚',
        'Select Courier' => '🚚',
        'Create Shipment' => '🚚',
        'Reconcile Shipment' => '🚚',
        'Assign AWB' => '🏷️',
        'Generate Label' => '🏷️',
        'Request Pickup' => '📦',
        'Generate Manifest' => '📋',
        'Upload Package Photo' => '📷',
    ];
    $shipmentActions = ['Get Courier Options', 'Select Courier', 'Create Shipment', 'Reconcile Shipment'];
    $title = in_array($action, $shipmentActions, true) ? 'Start Shipment' : $action;
    $subtitle = $subtitles[$action] ?? 'Confirm this hardware operation.';
    $icon = $icons[$action] ?? '📦';
    $details = $row->productDetails();
    $productSummary = $row->productMissing
        ? 'Product data missing'
        : collect($details)->map(function (array $line): string {
            return $line['qty'] !== null ? $line['label'].' · Qty '.$line['qty'] : $line['label'];
        })->implode(', ');
@endphp

<div class="workspace-note-dialog c360-dialog c360-correction-dialog hardware-action-dialog"
     data-c360-dialog
     data-hardware-action-dialog-root
     data-hardware-action="{{ $action }}"
     data-hardware-fulfilment-id="{{ $fulfilment->id }}"
     @if($incidentId) data-hardware-incident-id="{{ $incidentId }}" @endif>
    <x-c360.dialog-header
        :icon="$icon"
        :title="$title"
        :subtitle="$subtitle" />

    <div class="modal-body workspace-note-dialog-body c360-dialog-body pt-2">
        <x-c360.dialog-body-layout>
            <x-slot:sidebar>
                <section class="c360-dialog-identity c360-dialog-identity--sidebar" aria-label="Order summary">
                    <dl class="c360-dialog-identity-dl">
                        <div class="c360-dialog-identity-row">
                            <dt>Order</dt>
                            <dd><span class="c360-dialog-identity-value-text font-monospace">{{ $row->sourceId }}</span></dd>
                        </div>
                        <div class="c360-dialog-identity-row">
                            <dt>Customer</dt>
                            <dd><span class="c360-dialog-identity-value-text">{{ $row->customer }}</span></dd>
                        </div>
                        <div class="c360-dialog-identity-row">
                            <dt>Product</dt>
                            <dd><span class="c360-dialog-identity-value-text">{{ $productSummary !== '' ? $productSummary : $row->productDisplay() }}</span></dd>
                        </div>
                        <div class="c360-dialog-identity-row">
                            <dt>Quantity</dt>
                            <dd><span class="c360-dialog-identity-value-text">{{ $row->quantity !== '—' && $row->quantity !== '' ? $row->quantity : '—' }}</span></dd>
                        </div>
                        @if($row->serialDisplay() !== '—')
                            <div class="c360-dialog-identity-row">
                                <dt>Serial</dt>
                                <dd><span class="c360-dialog-identity-value-text font-monospace">{{ $row->serialDisplay() }}</span></dd>
                            </div>
                        @endif
                        <div class="c360-dialog-identity-row">
                            <dt>Payment</dt>
                            <dd><span class="c360-dialog-identity-value-text">{{ $row->payment }}</span></dd>
                        </div>
                    </dl>
                </section>
            </x-slot:sidebar>

            <div class="c360-dialog-step">
                @if($action === 'Allocate Serial')
                    @include('inventory.hardware-fulfilments.fragments.action-allocate-serial')
                @elseif($action === 'Issue Invoice')
                    @include('inventory.hardware-fulfilments.fragments.action-issue-invoice')
                @elseif(in_array($action, $shipmentActions, true))
                    @include('inventory.hardware-fulfilments.fragments.action-start-shipment', [
                        'showUrl' => $showUrl ?? null,
                    ])
                @elseif($action === 'Assign AWB')
                    @include('inventory.hardware-fulfilments.fragments.action-confirm', [
                        'formAction' => route('inventory.hardware-fulfilments.awb.store', $fulfilment),
                        'formId' => 'hardware-action-awb-form',
                        'summary' => [
                            'Courier' => $ready->courier ?? 'Not selected',
                            'Shipment' => $ready->status,
                        ],
                        'submitLabel' => 'Assign AWB',
                    ])
                @elseif($action === 'Generate Label')
                    @include('inventory.hardware-fulfilments.fragments.action-confirm', [
                        'formAction' => route('inventory.hardware-fulfilments.label.store', $fulfilment),
                        'formId' => 'hardware-action-label-form',
                        'summary' => [
                            'Courier' => $ready->courier ?? 'Not selected',
                            'AWB' => $ready->awb ?? 'Not assigned',
                        ],
                        'submitLabel' => 'Generate Label',
                    ])
                @elseif($action === 'Request Pickup')
                    @include('inventory.hardware-fulfilments.fragments.action-confirm', [
                        'formAction' => route('inventory.hardware-fulfilments.pickup.store', $fulfilment),
                        'formId' => 'hardware-action-pickup-form',
                        'summary' => [
                            'Courier' => $ready->courier ?? 'Not selected',
                            'AWB' => $ready->awb ?? 'Not assigned',
                        ],
                        'submitLabel' => 'Request Pickup',
                    ])
                @elseif($action === 'Generate Manifest')
                    @include('inventory.hardware-fulfilments.fragments.action-confirm', [
                        'formAction' => route('inventory.hardware-fulfilments.manifest.store', $fulfilment),
                        'formId' => 'hardware-action-manifest-form',
                        'summary' => [
                            'Order' => $row->sourceId,
                            'Courier' => $ready->courier ?? 'Not selected',
                            'AWB' => $ready->awb ?? 'Not assigned',
                        ],
                        'submitLabel' => 'Generate Manifest',
                    ])
                @elseif($action === 'Upload Package Photo')
                    @include('inventory.hardware-fulfilments.fragments.action-package-photo')
                @else
                    <x-c360.section-card title="Next action">
                        <p class="small mb-0">Open Fulfilment to continue this order.</p>
                    </x-c360.section-card>
                @endif
            </div>
        </x-c360.dialog-body-layout>
    </div>
</div>
