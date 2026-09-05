@extends('layouts.app')

@section('title', $sale->sale_no)

@section('content')
    <div class="mb-4 d-flex justify-content-between flex-wrap gap-2">
        <div>
            <p class="text-muted small text-uppercase fw-semibold mb-1">POS</p>
            <h1 class="h3 mb-1">{{ $sale->sale_no }}</h1>
            <p class="text-muted mb-0">
                Internal receipt {{ $sale->invoice_number }} · {{ $sale->status->label() }} ·
                Finance {{ $sale->finance_handoff_status->label() }}
                @if($sale->statutoryInvoice)
                    · GST invoice {{ $sale->statutoryInvoice->invoice_number }}
                @else
                    · No statutory GST invoice
                @endif
            </p>
        </div>
        <a href="{{ route('pos.sales.invoice', $sale) }}" class="btn btn-outline-secondary">Invoice</a>
    </div>
    @include('pos.partials.workspace-nav', ['active' => 'sales'])

    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 text-muted">Customer</h2>
                    <div>{{ $sale->customer?->name }}</div>
                    <div>{{ $sale->customer?->phone }}</div>
                    <div>{{ $sale->customer?->email ?: '—' }}</div>
                    <div class="mt-3 small">
                        <div class="text-muted text-uppercase fw-semibold">Sale statutory snapshot</div>
                        <div>GSTIN {{ $sale->buyer_gstin ?: 'B2C / not captured' }}</div>
                        <div>Place of supply {{ $sale->place_of_supply_state ?: 'not captured' }}</div>
                        <div>{{ $sale->billing_address ?: 'No billing address captured' }}</div>
                        <div class="text-muted">Finance Hub issues the GST invoice later. This sale did not mint one.</div>
                    </div>
                    <div class="mt-2 small text-muted">{{ $sale->branch?->name }} · {{ $sale->payment_method }}</div>
                    @if($sale->upiIntent)
                        <div class="small mt-2">
                            UPI {{ $sale->upiIntent->public_ref }}
                            · {{ $sale->upiIntent->receivingAccountLabel() }}
                            @if($sale->payment_reference)
                                · UTR {{ $sale->payment_reference }}
                            @endif
                        </div>
                    @elseif($sale->payment_reference)
                        <div class="small text-muted">Ref {{ $sale->payment_reference }}</div>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 text-muted">Totals</h2>
                    <div>Subtotal {{ number_format((float) $sale->subtotal, 2) }}</div>
                    <div>Discount {{ number_format((float) $sale->discount, 2) }}</div>
                    <div>Tax {{ number_format((float) $sale->tax, 2) }}</div>
                    <div class="fw-semibold">Total {{ number_format((float) $sale->total, 2) }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Qty</th>
                        <th>Price</th>
                        <th>Tax</th>
                        <th>Total</th>
                        <th>Serials</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($sale->lines as $line)
                        <tr>
                            <td>{{ $line->catalogLabel() }}</td>
                            <td>{{ $line->qty }}</td>
                            <td>{{ number_format((float) $line->unit_price, 2) }}</td>
                            <td>{{ number_format((float) $line->tax, 2) }}</td>
                            <td>{{ number_format((float) $line->line_total, 2) }}</td>
                            <td>
                                @foreach($line->serials as $assignment)
                                    <div><a href="{{ route('inventory.serials.show', $assignment->serial) }}">{{ $assignment->serial?->serial_number }}</a></div>
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if($canCancel && $sale->status === \App\Enums\InventorySaleStatus::Completed)
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h2 class="h6">Cancel / return</h2>
                <p class="text-muted small">Restores serials and quantity to the selling branch and posts a reversing finance journal when the sale was posted. Invoice number is kept. This is not a GST credit note.</p>
                @if($sale->upiIntent || strcasecmp((string) $sale->payment_method, 'UPI') === 0)
                    <div class="alert alert-warning">
                        Cancelling or returning this UPI sale reverses Desk stock and the finance journal only. It does <strong>not</strong> refund the customer through UPI or the bank. Refund any bank credit separately.
                    </div>
                @endif
                <form method="POST" action="{{ route('pos.sales.cancel', $sale) }}" class="d-flex flex-wrap gap-2 mb-2" data-once-submit>
                    @csrf
                    <input type="text" name="reason" id="cancel-reason" class="form-control" style="max-width: 24rem;" required placeholder="Cancel reason" aria-label="Cancel reason">
                    <button class="btn btn-outline-danger">Cancel sale</button>
                </form>
                <form method="POST" action="{{ route('pos.sales.return', $sale) }}" class="d-flex flex-wrap gap-2" data-once-submit>
                    @csrf
                    <input type="text" name="reason" id="return-reason" class="form-control" style="max-width: 24rem;" required placeholder="Return reason" aria-label="Return reason">
                    <button class="btn btn-outline-secondary">Return sale</button>
                </form>
            </div>
        </div>
    @endif
@endsection

@push('scripts')
    <script>
        document.querySelectorAll('form[data-once-submit]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (form.dataset.submitting === '1') {
                    event.preventDefault();
                    return;
                }
                form.dataset.submitting = '1';
                form.querySelectorAll('button[type="submit"], button:not([type])').forEach(function (button) {
                    button.disabled = true;
                    button.setAttribute('aria-busy', 'true');
                });
            });
        });
    </script>
@endpush
