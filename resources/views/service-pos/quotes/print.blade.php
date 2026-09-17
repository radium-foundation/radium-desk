<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $quote->quote_number }} — Proforma</title>
    <style>body{font-family:system-ui,sans-serif;max-width:800px;margin:2rem auto} table{width:100%;border-collapse:collapse} th,td{border:1px solid #ccc;padding:.5rem;text-align:left} .muted{color:#666}</style>
</head>
<body>
    <h1>Internal Proforma / Quote</h1>
    <p class="muted">Not a statutory GST invoice.</p>
    <p><strong>{{ $quote->quote_number }}</strong> · {{ $quote->created_at?->format('d M Y') }}</p>
    <p>{{ $quote->buyer_name }} · {{ $quote->buyer_phone }}<br>{{ $quote->billing_state }}</p>
    <table>
        <thead><tr><th>Description</th><th>SAC</th><th>Qty</th><th>Ex-GST</th><th>Tax</th><th>Total</th></tr></thead>
        <tbody>
            @foreach($quote->lines as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td>{{ $line->sac_code }}</td>
                    <td>{{ $line->qty }}</td>
                    <td>{{ number_format((float) $line->unit_price_ex_gst, 2) }}</td>
                    <td>{{ number_format((float) $line->tax_total, 2) }}</td>
                    <td>{{ number_format((float) $line->line_total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p><strong>Total: ₹{{ number_format((float) $quote->total, 2) }}</strong></p>
</body>
</html>
