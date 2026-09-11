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
                @if($statutoryInvoice)
                    · GST invoice {{ $statutoryInvoice->invoice_number }}
                @else
                    · No statutory GST invoice
                @endif
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('pos.sales.invoice', $sale) }}" class="btn btn-outline-secondary">Internal receipt</a>
            @if($statutoryInvoice)
                <a href="{{ route('finance.invoices.show', $statutoryInvoice) }}" class="btn btn-outline-primary">View GST invoice</a>
                <a href="{{ route('pos.sales.statutory-invoice.pdf', $sale) }}" class="btn btn-outline-primary">Download PDF</a>
            @endif
        </div>
    </div>
    @include('pos.partials.workspace-nav', ['active' => 'sales'])

    @if($statutoryInvoice)
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h2 class="h6 text-muted">Invoice status</h2>
                <div class="row g-2 small">
                    <div class="col-md-3"><strong>Sale</strong> Completed</div>
                    <div class="col-md-3"><strong>Invoice</strong> Generated ({{ $statutoryInvoice->invoice_number }})</div>
                    <div class="col-md-3">
                        <strong>IRN</strong>
                        @php($irn = $statutoryInvoice->eInvoiceRecord?->irn)
                        @if($irn)
                            Generated
                        @elseif($statutoryInvoice->buyer_gstin)
                            {{ $statutoryInvoice->eInvoiceRecord?->status ?: 'Pending / not applicable' }}
                        @else
                            Not applicable (B2C)
                        @endif
                    </div>
                    <div class="col-md-3">
                        <strong>PDF</strong>
                        {{ $statutoryInvoice->document?->status?->label() ?? 'Pending' }}
                    </div>
                    <div class="col-md-3">
                        <strong>Email</strong>
                        @if($emailDispatches->where('status', 'sent')->isNotEmpty())
                            Sent
                        @else
                            Not sent
                        @endif
                    </div>
                    <div class="col-md-3"><strong>WhatsApp</strong> Ready</div>
                </div>
            </div>
        </div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 text-muted">Customer</h2>
                    <div>{{ $sale->snapshot_buyer_name ?? $sale->customer?->name }}</div>
                    <div>{{ $sale->snapshot_buyer_phone ?? $sale->customer?->phone }}</div>
                    <div>{{ $sale->snapshot_buyer_email ?? $sale->customer?->email ?: '—' }}</div>
                    <div class="mt-3 small">
                        <div class="text-muted text-uppercase fw-semibold">Sale statutory snapshot</div>
                        <div>Type {{ strtoupper((string) ($sale->customer_type ?: 'b2c')) }}</div>
                        <div>GSTIN {{ $sale->buyer_gstin ?: 'B2C / not captured' }}</div>
                        <div>Place of supply {{ $sale->place_of_supply_state ?: 'not captured' }}</div>
                        @if($sale->place_of_supply_source)
                            <div class="text-muted">POS source {{ $sale->place_of_supply_source }}</div>
                        @endif
                        <div>{{ $sale->billing_address ?: 'No billing address captured' }}</div>
                        @if($sale->billing_state)
                            <div>{{ $sale->billing_city }} {{ $sale->billing_state }} {{ $sale->billing_postal_code }}</div>
                        @endif
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

    @if($statutoryInvoice)
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h2 class="h6">Share invoice</h2>
                <form method="POST" action="{{ route('pos.sales.statutory-invoice.email', $sale) }}" class="row g-2 align-items-end mb-3">
                    @csrf
                    <div class="col-md-6">
                        <label class="form-label" for="invoice-email">Email invoice</label>
                        <input type="email" name="email" id="invoice-email" class="form-control" required
                               value="{{ old('email', $sale->snapshot_buyer_email ?? $sale->customer?->email) }}">
                        @error('email')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-auto">
                        <button class="btn btn-primary">Send email</button>
                    </div>
                </form>
                @if($whatsAppShareUrl)
                    <a href="{{ $whatsAppShareUrl }}" class="btn btn-success" target="_blank" rel="noopener">WhatsApp share</a>
                @endif
                @if($emailDispatches->isNotEmpty())
                    <div class="small text-muted mt-3">
                        @foreach($emailDispatches as $dispatch)
                            <div>{{ $dispatch->destination }} · {{ $dispatch->status }} · {{ optional($dispatch->sent_at)->format('d M Y H:i') }}</div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif

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
