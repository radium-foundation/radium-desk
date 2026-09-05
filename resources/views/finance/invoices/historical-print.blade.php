<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $result->invoiceNumber }}</title>
    <style>
        :root {
            --ink: #121417;
            --muted: #5f6772;
            --rule: #e4e6ea;
            --surface: #f5f6f8;
            --accent: #c8102e;
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            background: #fff;
            color: var(--ink);
            font-family: "Segoe UI", "Helvetica Neue", Helvetica, Arial, sans-serif;
            font-size: 11.5pt;
            line-height: 1.45;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .sheet {
            width: 210mm;
            margin: 0 auto;
            padding: 12mm 14mm 14mm;
        }

        .no-print {
            margin: 0 0 16px;
        }

        .no-print button {
            appearance: none;
            border: 1px solid var(--ink);
            background: var(--ink);
            color: #fff;
            font: inherit;
            font-size: 13px;
            padding: 8px 14px;
            border-radius: 6px;
            cursor: pointer;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 24px;
            padding-bottom: 16px;
            border-bottom: 2px solid var(--ink);
            break-inside: avoid;
        }

        .brand-mark {
            display: block;
            width: 196px;
            height: auto;
        }

        .seller-legal {
            margin: 6px 0 0;
            font-size: 10.5pt;
            color: var(--muted);
            letter-spacing: 0.01em;
        }

        .doc-meta {
            text-align: right;
            min-width: 42%;
        }

        .doc-kicker {
            margin: 0 0 4px;
            font-size: 9.5pt;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--muted);
            font-weight: 600;
        }

        .doc-title {
            margin: 0;
            font-size: 22pt;
            font-weight: 650;
            letter-spacing: -0.03em;
            line-height: 1.1;
        }

        .meta-grid {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 4px 12px;
            margin-top: 12px;
            justify-content: end;
        }

        .meta-grid dt {
            margin: 0;
            color: var(--muted);
            font-size: 9.5pt;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .meta-grid dd {
            margin: 0;
            font-weight: 650;
            font-variant-numeric: tabular-nums;
        }

        .parties {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 28px;
            margin: 22px 0 0;
            break-inside: avoid;
        }

        .party-label {
            margin: 0 0 6px;
            font-size: 9pt;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--muted);
            font-weight: 650;
        }

        .party-name {
            margin: 0 0 6px;
            font-size: 13pt;
            font-weight: 650;
        }

        .party-lines {
            margin: 0;
            color: var(--ink);
        }

        .party-lines p {
            margin: 0 0 2px;
        }

        .refs {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 18px;
            margin: 20px 0 0;
            padding: 10px 12px;
            background: var(--surface);
            break-inside: avoid;
        }

        .refs span {
            font-size: 10pt;
            color: var(--muted);
        }

        .refs strong {
            color: var(--ink);
            font-weight: 650;
            font-variant-numeric: tabular-nums;
        }

        table.lines {
            width: 100%;
            border-collapse: collapse;
            margin-top: 22px;
        }

        table.lines thead {
            display: table-header-group;
        }

        table.lines th {
            text-align: left;
            font-size: 8.5pt;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #fff;
            background: var(--ink);
            padding: 8px 10px;
            font-weight: 650;
        }

        table.lines td {
            padding: 9px 10px;
            border-bottom: 1px solid var(--rule);
            vertical-align: top;
        }

        table.lines tbody tr {
            break-inside: avoid;
        }

        table.lines .num {
            text-align: right;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }

        table.lines .desc {
            width: 42%;
            overflow-wrap: anywhere;
        }

        .empty-lines {
            color: var(--muted);
            font-style: italic;
        }

        .totals-wrap {
            display: flex;
            justify-content: flex-end;
            margin-top: 18px;
            break-inside: avoid;
        }

        .totals {
            width: 62mm;
        }

        .totals-row {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            padding: 5px 0;
            font-variant-numeric: tabular-nums;
        }

        .totals-row span:first-child {
            color: var(--muted);
        }

        .totals-row--total {
            margin-top: 6px;
            padding: 10px 12px;
            background: var(--ink);
            color: #fff;
            font-size: 13pt;
            font-weight: 700;
        }

        .totals-row--total span:first-child {
            color: #d7dbe0;
        }

        .tax-note {
            margin: 8px 0 0;
            color: var(--muted);
            font-size: 9.5pt;
        }

        .einvoice {
            margin-top: 18px;
            padding-top: 12px;
            border-top: 1px solid var(--rule);
            font-size: 10pt;
            overflow-wrap: anywhere;
            break-inside: avoid;
        }

        .footer {
            margin-top: 28px;
            padding-top: 12px;
            border-top: 1px solid var(--rule);
            color: var(--muted);
            font-size: 9pt;
            break-inside: avoid;
        }

        .footer p {
            margin: 0 0 4px;
        }

        @page {
            size: A4;
            margin: 10mm 10mm 12mm;
        }

        @media print {
            .no-print { display: none !important; }
            .sheet {
                width: auto;
                min-height: 0;
                margin: 0;
                padding: 0;
            }
        }

        @media screen {
            body { background: #eceef2; }
            .sheet {
                background: #fff;
                box-shadow: 0 10px 40px rgba(18, 20, 23, 0.08);
                margin: 16px auto 32px;
            }
        }
    </style>
</head>
<body>
    @php
        $seller = is_array($reprint['seller'] ?? null) ? $reprint['seller'] : [];
        $buyer = is_array($reprint['buyer'] ?? null) ? $reprint['buyer'] : [];
        $totals = is_array($reprint['totals'] ?? null) ? $reprint['totals'] : [];
        $lines = is_array($reprint['lines'] ?? null) ? $reprint['lines'] : [];
        $einvoice = is_array($reprint['einvoice'] ?? null) ? $reprint['einvoice'] : [];

        $filled = static function (mixed $value): bool {
            return is_string($value) ? trim($value) !== '' : $value !== null;
        };

        $text = static function (mixed $value): ?string {
            if (! is_scalar($value)) {
                return null;
            }

            $string = trim((string) $value);

            return $string !== '' ? $string : null;
        };

        $money = static function (mixed $value): ?string {
            if ($value === null || $value === '') {
                return null;
            }

            if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                return number_format((float) $value, 2);
            }

            return is_scalar($value) ? (string) $value : null;
        };

        $qty = static function (mixed $value): string {
            if (is_int($value) || (is_numeric($value) && (float) $value == (int) $value)) {
                return (string) (int) $value;
            }

            if (is_numeric($value)) {
                return number_format((float) $value, 2);
            }

            return is_scalar($value) && (string) $value !== '' ? (string) $value : '—';
        };

        $rawDate = $text($reprint['invoice_date'] ?? null);
        $dateDisplay = $rawDate;
        if ($rawDate !== null) {
            try {
                $dateDisplay = \Illuminate\Support\Carbon::parse($rawDate)->format('d M Y');
            } catch (\Throwable) {
                $dateDisplay = $rawDate;
            }
        }

        $sellerName = $text($seller['legal_name'] ?? null) ?? 'Phil Technologies (P) Limited';
        $orderCode = $text($reprint['ordercode'] ?? null);
        $rdOrderId = $text($reprint['rdorderid'] ?? null);
        $ordersId = $reprint['orders_id'] ?? null;
        $logoUrl = asset('brand/logo.svg');
    @endphp

    <div class="sheet">
        <p class="no-print"><button type="button" onclick="window.print()">Print</button></p>

        <header class="header">
            <div>
                <img class="brand-mark" src="{{ $logoUrl }}" alt="Radium">
                <p class="seller-legal">{{ $sellerName }}</p>
            </div>
            <div class="doc-meta">
                <p class="doc-kicker">Tax invoice</p>
                <h1 class="doc-title">{{ $result->invoiceNumber }}</h1>
                <dl class="meta-grid">
                    @if($dateDisplay !== null)
                        <dt>Date</dt>
                        <dd>{{ $dateDisplay }}</dd>
                    @endif
                    @if($orderCode !== null)
                        <dt>Order</dt>
                        <dd>{{ $orderCode }}</dd>
                    @endif
                </dl>
            </div>
        </header>

        <section class="parties">
            <div>
                <p class="party-label">Seller</p>
                <p class="party-name">{{ $sellerName }}</p>
                <div class="party-lines">
                    @if($filled($seller['address'] ?? null))
                        <p>{{ $seller['address'] }}</p>
                    @endif
                    @if($filled($seller['gstin'] ?? null))
                        <p>GSTIN {{ $seller['gstin'] }}</p>
                    @endif
                    @if($filled($seller['email'] ?? null))
                        <p>{{ $seller['email'] }}</p>
                    @endif
                    @if($filled($seller['phone'] ?? null))
                        <p>{{ $seller['phone'] }}</p>
                    @endif
                </div>
            </div>
            <div>
                <p class="party-label">Buyer</p>
                <p class="party-name">{{ $text($buyer['name'] ?? null) ?? '—' }}</p>
                <div class="party-lines">
                    @if($filled($buyer['address'] ?? null))
                        <p>{{ $buyer['address'] }}</p>
                    @endif
                    @if($filled($buyer['gst_no'] ?? null))
                        <p>GSTIN {{ $buyer['gst_no'] }}</p>
                    @endif
                    @if($filled($buyer['email'] ?? null))
                        <p>{{ $buyer['email'] }}</p>
                    @endif
                    @if($filled($buyer['phone'] ?? null))
                        <p>{{ $buyer['phone'] }}</p>
                    @endif
                </div>
            </div>
        </section>

        @if($rdOrderId !== null || is_numeric($ordersId))
            <div class="refs">
                @if($rdOrderId !== null)
                    <span>Reference <strong>{{ $rdOrderId }}</strong></span>
                @endif
                @if(is_numeric($ordersId))
                    <span>Source order <strong>{{ $ordersId }}</strong></span>
                @endif
            </div>
        @endif

        <table class="lines">
            <thead>
                <tr>
                    <th class="desc">Description</th>
                    <th>HSN</th>
                    <th class="num">Qty</th>
                    <th class="num">Rate</th>
                    <th class="num">Tax</th>
                    <th class="num">Amount</th>
                </tr>
            </thead>
            <tbody>
                @forelse($lines as $line)
                    <tr>
                        <td class="desc">{{ $text($line['product_name'] ?? null) ?? '—' }}</td>
                        <td>{{ $text($line['hsn_code'] ?? null) ?? '—' }}</td>
                        <td class="num">{{ $qty($line['qty'] ?? null) }}</td>
                        <td class="num">{{ $money($line['price'] ?? null) ?? '—' }}</td>
                        <td class="num">{{ $money($line['tax'] ?? null) ?? '—' }}</td>
                        <td class="num">{{ $money($line['total'] ?? null) ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td class="empty-lines" colspan="6">Line detail was not returned by the source API.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="totals-wrap">
            <div class="totals">
                @if($money($totals['tax'] ?? null) !== null)
                    <div class="totals-row">
                        <span>Tax</span>
                        <span>{{ $money($totals['tax']) }}</span>
                    </div>
                @endif
                @if($filled($totals['cgst'] ?? null))
                    <div class="totals-row">
                        <span>CGST</span>
                        <span>{{ $money($totals['cgst']) }}</span>
                    </div>
                @endif
                @if($filled($totals['sgst'] ?? null))
                    <div class="totals-row">
                        <span>SGST</span>
                        <span>{{ $money($totals['sgst']) }}</span>
                    </div>
                @endif
                @if($filled($totals['igst'] ?? null))
                    <div class="totals-row">
                        <span>IGST</span>
                        <span>{{ $money($totals['igst']) }}</span>
                    </div>
                @endif
                <div class="totals-row totals-row--total">
                    <span>Total</span>
                    <span>{{ $money($totals['total'] ?? null) ?? '—' }}</span>
                </div>
                <p class="tax-note">Amounts shown as returned by the source invoice. Not recalculated.</p>
            </div>
        </div>

        @if($filled($einvoice['irn'] ?? null))
            <div class="einvoice">
                IRN {{ $einvoice['irn'] }}
                @if($filled($einvoice['ack_no'] ?? null))
                    · Ack {{ $einvoice['ack_no'] }}
                @endif
                @if($filled($einvoice['ack_date'] ?? null))
                    {{ $einvoice['ack_date'] }}
                @endif
            </div>
        @endif

        <footer class="footer">
            <p>Historical reprint — existing invoice {{ $result->invoiceNumber }}. Not a new tax invoice. Number unchanged.</p>
            <p>{{ $sellerName }}</p>
        </footer>
    </div>
</body>
</html>
