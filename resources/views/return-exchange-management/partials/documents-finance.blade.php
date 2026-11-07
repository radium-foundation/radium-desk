@php
    $sale = $case->inventorySale;
    $invoice = $sale?->statutoryInvoice;
@endphp
<div class="card mb-3">
    <div class="card-header py-2"><span class="small fw-semibold">Documents &amp; finance (read-only)</span></div>
    <div class="card-body small">
        <dl class="row mb-0">
            <dt class="col-sm-4">POS sale</dt>
            <dd class="col-sm-8">{{ $sale?->sale_no ?? '—' }}</dd>
            <dt class="col-sm-4">Original invoice</dt>
            <dd class="col-sm-8">{{ $invoice?->invoice_number ?? '—' }} ({{ $invoice?->status?->value ?? '—' }})</dd>
            <dt class="col-sm-4">Finance journal</dt>
            <dd class="col-sm-8">{{ $sale?->finance_journal_id ?? '—' }}</dd>
            <dt class="col-sm-4">Price difference</dt>
            <dd class="col-sm-8">
                @if ($case->price_difference_amount !== null)
                    ₹{{ $case->price_difference_amount }}
                    — {{ $case->price_difference_verified ? 'Verified' : 'Pending verification' }}
                @else
                    —
                @endif
            </dd>
            <dt class="col-sm-4">Return serial</dt>
            <dd class="col-sm-8">{{ $case->lines->first()?->serial_number ?? '—' }}</dd>
            <dt class="col-sm-4">Sale serial assignments</dt>
            <dd class="col-sm-8">
                @if ($sale)
                    {{ $sale->serials->map(fn ($r) => $r->serial?->serial_number)->filter()->join(', ') }}
                @else
                    —
                @endif
            </dd>
        </dl>
    </div>
</div>
