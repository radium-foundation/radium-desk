@php
    $action = $row->nextAction;
    $details = $row->productDetails();
    $productSummary = $row->productMissing
        ? $row->productDisplay()
        : collect($details)->map(function (array $line): string {
            return $line['qty'] !== null ? $line['label'].' · Qty '.$line['qty'] : $line['label'];
        })->implode(', ');
@endphp

<div class="workspace-note-dialog c360-dialog c360-correction-dialog hardware-action-dialog"
     data-c360-dialog
     data-hardware-action-dialog-root
     data-hardware-action="{{ $action }}"
     data-hardware-support-order-id="{{ $order->id }}"
     @if($incidentId) data-hardware-incident-id="{{ $incidentId }}" @endif>
    <x-c360.dialog-header
        icon="📦"
        title="Open Fulfilment"
        subtitle="Open exactly one Hardware Fulfilment from the existing paid Commerce order. markReady() remains the READY gate." />

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
                        <div class="c360-dialog-identity-row">
                            <dt>Payment</dt>
                            <dd><span class="c360-dialog-identity-value-text">{{ $row->payment }}</span></dd>
                        </div>
                    </dl>
                </section>
            </x-slot:sidebar>

            <div class="c360-dialog-step">
                @include('inventory.hardware-fulfilments.fragments.action-confirm', [
                    'formAction' => route('inventory.hardware-fulfilments.awaiting.open', $order),
                    'formId' => 'hardware-action-open-fulfilment-form',
                    'summary' => [
                        'State' => 'Recovered Commerce',
                        'Payment' => $row->payment,
                        'Product' => $productSummary !== '' ? $productSummary : $row->productDisplay(),
                    ],
                    'submitLabel' => 'Open Fulfilment',
                ])
            </div>
        </x-c360.dialog-body-layout>
    </div>
</div>
