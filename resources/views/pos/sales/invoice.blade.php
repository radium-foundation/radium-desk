<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $sale->invoice_number }}</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 2rem; color: #111; }
        h1 { font-size: 1.4rem; margin-bottom: 0.25rem; }
        table { width: 100%; border-collapse: collapse; margin-top: 1.5rem; }
        th, td { text-align: left; padding: 0.4rem 0.5rem; border-bottom: 1px solid #ddd; }
        .muted { color: #555; }
        .totals { margin-top: 1rem; max-width: 16rem; margin-left: auto; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
    <p class="no-print"><a href="{{ route('pos.sales.show', $sale) }}">Back to sale</a> · <button type="button" onclick="window.print()">Print</button></p>
    <h1>Invoice {{ $sale->invoice_number }}</h1>
    <p class="muted">{{ $sale->sale_no }} · {{ $sale->status->label() }} · {{ $sale->completed_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</p>
    <p class="muted">Internal Desk invoice — not a GST e-invoice / IRN.</p>
    <p>
        <strong>{{ $sale->branch?->name }}</strong><br>
        Branch {{ $sale->branch?->code }}
        @if($sale->branch?->gstin)<br>GSTIN {{ $sale->branch->gstin }}@endif
    </p>
    <p>
        Bill to: {{ $sale->customer?->name }}<br>
        {{ $sale->customer?->phone }}
        @if($sale->customer?->email)<br>{{ $sale->customer->email }}@endif
    </p>
    <table>
        <thead>
            <tr>
                <th>Item</th>
                <th>Qty</th>
                <th>Price</th>
                <th>GST %</th>
                <th>Tax</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sale->lines as $line)
                <tr>
                    <td>
                        {{ $line->catalogLabel() }}
                        @if($line->serials->isNotEmpty())
                            <div class="muted">
                                @foreach($line->serials as $assignment)
                                    {{ $assignment->serial?->serial_number }}@if(! $loop->last), @endif
                                @endforeach
                            </div>
                        @endif
                    </td>
                    <td>{{ $line->qty }}</td>
                    <td>{{ number_format((float) $line->unit_price, 2) }}</td>
                    <td>{{ $line->gst_percentage }}</td>
                    <td>{{ number_format((float) $line->tax, 2) }}</td>
                    <td>{{ number_format((float) $line->line_total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <div class="totals">
        <div>Subtotal {{ number_format((float) $sale->subtotal, 2) }}</div>
        <div>Discount {{ number_format((float) $sale->discount, 2) }}</div>
        <div>Tax {{ number_format((float) $sale->tax, 2) }}</div>
        <div><strong>Total {{ number_format((float) $sale->total, 2) }}</strong></div>
        <div class="muted">Paid by {{ $sale->payment_method }}</div>
    </div>
</body>
</html>
