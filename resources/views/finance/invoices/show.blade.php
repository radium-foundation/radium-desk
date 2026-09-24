@extends('layouts.app')

@section('title', 'Invoice '.$invoice->invoice_number)

@section('content')
    <div class="mb-4 d-flex justify-content-between flex-wrap gap-2">
        <div>
            <p class="text-muted small text-uppercase fw-semibold mb-1">Finance</p>
            <h1 class="h3 mb-1">{{ $invoice->invoice_number }}</h1>
            <p class="text-muted mb-0">
                {{ $invoice->document_type->label() }} · {{ $invoice->status->label() }} ·
                {{ $invoice->channel->label() }} · source {{ $invoice->source_type }} {{ $invoice->source_id }}
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            @if(!empty($canCancelInvoice))
                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelInvoiceModal">
                    Cancel invoice
                </button>
            @endif
            @if(!empty($canReevaluateEinvoice))
                <form method="POST" action="{{ route('finance.invoices.reevaluate-einvoice', $invoice) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-primary">Re-evaluate e-invoice eligibility</button>
                </form>
            @endif
            <a href="{{ route('finance.invoices.pdf', $invoice) }}" class="btn btn-outline-secondary">GST PDF</a>
        </div>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'invoices'])

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if($invoice->status->value === 'cancelled')
        <div class="alert alert-warning">
            Cancelled on {{ $invoice->cancelled_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}.
            The invoice number is kept and is not reused.
            @if($invoice->cancelledBy)
                Cancelled by {{ $invoice->cancelledBy->name }}.
            @endif
            @if($invoice->cancel_reason)
                Reason: {{ $invoice->cancel_reason }}
            @endif
        </div>
        @if($invoice->cancellation)
            <div class="card shadow-sm mb-4">
                <div class="card-body small">
                    <h2 class="h6 mb-2">Cancellation result</h2>
                    <p class="mb-1"><strong>IRN:</strong> {{ data_get($invoice->cancellation->irn_action, 'message', '—') }}</p>
                    <p class="mb-1"><strong>Inventory:</strong> {{ data_get($invoice->cancellation->inventory_action, 'message', '—') }}</p>
                    <p class="mb-0"><strong>Credit note:</strong> {{ data_get($invoice->cancellation->credit_note_action, 'message', '—') }}</p>
                </div>
            </div>
        @endif
    @endif

    <p>Buyer: {{ $invoice->buyer_name ?: '—' }} · GSTIN {{ $invoice->buyer_gstin ?: 'B2C' }}</p>
    <p>Seller: {{ $invoice->seller_name ?: '—' }} · GSTIN {{ $invoice->seller_gstin ?: '—' }}</p>
    <p>Place of supply {{ $invoice->place_of_supply_state ?: 'unset' }}</p>
    @if($invoice->payment_reference)
        <p>PO / reference {{ $invoice->payment_reference }}</p>
    @endif
    @if($invoice->eInvoiceRecord)
        <p class="text-muted small mb-0">
            E-invoice {{ $invoice->eInvoiceRecord->status }}
            @if($invoice->eInvoiceRecord->irn)
                · IRN present
            @elseif(is_array($invoice->eInvoiceRecord->response_payload))
                · {{ $invoice->eInvoiceRecord->response_payload['skip_reason'] ?? $invoice->eInvoiceRecord->response_payload['queue_reason'] ?? '—' }}
            @endif
        </p>
    @endif
    @if($invoice->inventorySale?->invoice_number)
        <p class="text-muted">POS internal receipt {{ $invoice->inventorySale->invoice_number }} (not a GST number)</p>
    @endif

    <div class="table-responsive">
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>HSN/SAC</th>
                    <th>Qty</th>
                    <th>Taxable</th>
                    <th>GST %</th>
                    <th>CGST</th>
                    <th>SGST</th>
                    <th>IGST</th>
                    <th>Tax</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($invoice->items as $line)
                    <tr>
                        <td>{{ $line->description }}</td>
                        <td>{{ $line->hsn_sac ?: '—' }}</td>
                        <td>{{ $line->qty }}</td>
                        <td>{{ number_format((float) $line->taxable_value, 2) }}</td>
                        <td>{{ $line->gst_percentage !== null ? number_format((float) $line->gst_percentage, 2) : '—' }}</td>
                        <td>{{ $line->cgst !== null ? number_format((float) $line->cgst, 2) : '—' }}</td>
                        <td>{{ $line->sgst !== null ? number_format((float) $line->sgst, 2) : '—' }}</td>
                        <td>{{ $line->igst !== null ? number_format((float) $line->igst, 2) : '—' }}</td>
                        <td>{{ number_format((float) $line->tax_total, 2) }}</td>
                        <td>{{ number_format((float) $line->line_total, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <p>
        Taxable {{ number_format((float) $invoice->taxable_value, 2) }}
        · Total GST {{ number_format((float) $invoice->tax_total, 2) }}
        · CGST {{ $invoice->cgst !== null ? number_format((float) $invoice->cgst, 2) : '—' }}
        · SGST {{ $invoice->sgst !== null ? number_format((float) $invoice->sgst, 2) : '—' }}
        · IGST {{ $invoice->igst !== null ? number_format((float) $invoice->igst, 2) : '—' }}
        · Invoice value {{ number_format((float) $invoice->invoice_value, 2) }}
    </p>

    @if(!empty($canCancelInvoice))
        <div class="modal fade" id="cancelInvoiceModal" tabindex="-1" aria-labelledby="cancelInvoiceModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <form method="POST" action="{{ route('finance.invoices.cancel', $invoice) }}" class="modal-content">
                    @csrf
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="cancelInvoiceModalLabel">Cancel statutory invoice</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-muted mb-3">
                            This action can affect IRN status, linked POS inventory, and statutory records.
                            The invoice number will be kept and is not reused.
                        </p>
                        @if(!empty($cancellationConsequences))
                            <ul class="small mb-3">
                                @foreach($cancellationConsequences as $consequence)
                                    <li>{{ $consequence }}</li>
                                @endforeach
                            </ul>
                        @endif
                        <div class="mb-3">
                            <label for="cancel-reason" class="form-label">Cancellation reason</label>
                            <textarea
                                id="cancel-reason"
                                name="reason"
                                class="form-control @error('reason') is-invalid @enderror"
                                rows="3"
                                required
                                minlength="3"
                                maxlength="2000"
                            >{{ old('reason') }}</textarea>
                            @error('reason')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="form-check">
                            <input
                                class="form-check-input @error('confirm') is-invalid @enderror"
                                type="checkbox"
                                value="1"
                                id="cancel-confirm"
                                name="confirm"
                                required
                            >
                            <label class="form-check-label" for="cancel-confirm">
                                I understand this cancellation may affect IRN, inventory, and statutory records.
                            </label>
                            @error('confirm')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-danger">Cancel invoice</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
@endsection
