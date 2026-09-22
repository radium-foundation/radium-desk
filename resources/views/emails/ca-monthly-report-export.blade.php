<p>Hello,</p>

<p>Your CA Monthly Report export is ready.</p>

<ul>
    <li><strong>Date range:</strong> {{ $export->dateRangeLabel() }}</li>
    <li><strong>Format:</strong> {{ $export->format->label() }}</li>
    @if ($export->row_count !== null)
        <li><strong>Rows:</strong> {{ number_format($export->row_count) }}</li>
    @endif
    <li><strong>Delivery:</strong> {{ $deliveryMode->label() }}</li>
</ul>

@if ($deliveryMode === \App\Enums\CaMonthlyReportEmailDeliveryMode::Attachment)
    <p>The report is attached to this email.</p>
@elseif ($downloadUrl)
    <p>The report is too large to attach. Use this secure download link before it expires:</p>
    <p><a href="{{ $downloadUrl }}">Download CA Monthly Report</a></p>
@endif

<p>This link and any attached file are confidential. Do not forward outside authorized Finance recipients.</p>
