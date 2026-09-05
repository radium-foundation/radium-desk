<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $result->invoiceNumber }}</title>
    <style>
        body { font-family: "Source Sans 3", "Segoe UI", sans-serif; color: #111; margin: 24px; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .meta { color: #555; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border: 1px solid #ccc; padding: 6px 8px; font-size: 13px; text-align: left; }
        .banner { border: 1px solid #111; padding: 8px 12px; margin-bottom: 16px; font-size: 13px; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
    <p class="no-print"><button type="button" onclick="window.print()">Print</button></p>
    <div class="banner">Historical reprint — existing invoice {{ $result->invoiceNumber }}. Not a new tax invoice. Number unchanged.</div>
    <h1>Tax Invoice {{ $result->invoiceNumber }}</h1>
    <p class="meta">
        Date {{ $reprint['invoice_date'] ?? '—' }}
        · Order {{ $reprint['ordercode'] ?? $reprint['rdorderid'] ?? '—' }}
    </p>
    <p>
        <strong>Seller</strong><br>
        {{ $reprint['seller']['legal_name'] ?? 'Phil Technologies (P) Limited' }}<br>
        {{ $reprint['seller']['address'] ?? '' }}<br>
        GSTIN {{ $reprint['seller']['gstin'] ?? '—' }}
    </p>
    <p>
        <strong>Buyer</strong><br>
        {{ $reprint['buyer']['name'] ?? '—' }}<br>
        {{ $reprint['buyer']['address'] ?? '' }}<br>
        GSTIN {{ $reprint['buyer']['gst_no'] ?? '—' }}
        · {{ $reprint['buyer']['phone'] ?? '' }}
    </p>
    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th>HSN</th>
                <th>Qty</th>
                <th>Price</th>
                <th>Tax</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse(($reprint['lines'] ?? []) as $line)
                <tr>
                    <td>{{ $line['product_name'] ?? '—' }}</td>
                    <td>{{ $line['hsn_code'] ?? '—' }}</td>
                    <td>{{ $line['qty'] ?? '—' }}</td>
                    <td>{{ $line['price'] ?? '—' }}</td>
                    <td>{{ $line['tax'] ?? '—' }}</td>
                    <td>{{ $line['total'] ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="6">Line detail was not returned by the source API.</td></tr>
            @endforelse
        </tbody>
    </table>
    <p>Tax {{ $reprint['totals']['tax'] ?? '—' }} · Total {{ $reprint['totals']['total'] ?? '—' }}</p>
    @if(!empty($reprint['einvoice']['irn']))
        <p>IRN {{ $reprint['einvoice']['irn'] }} · Ack {{ $reprint['einvoice']['ack_no'] ?? '—' }} {{ $reprint['einvoice']['ack_date'] ?? '' }}</p>
    @endif
</body>
</html>
