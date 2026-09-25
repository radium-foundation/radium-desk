<p>Hello{{ filled($customerName ?? null) ? ' '.$customerName : '' }},</p>

<p>We have received your payment for order <strong>{{ $orderId }}</strong>. Thank you.</p>

<p>
    Statutory invoice generation is pending because the GST/tax details supplied with the order need verification
    before we can issue your invoice.
</p>

<ul>
    <li><strong>GSTIN supplied:</strong> {{ $gstin }}</li>
    <li><strong>GSTIN state:</strong> {{ $gstinState }}</li>
    <li><strong>Billing state supplied:</strong> {{ $billingState }}</li>
    <li><strong>Issue detected:</strong> {{ $validationReason }}</li>
</ul>

<p>
    Please reply with the corrected GST registration details and billing state information, or contact our support team,
    so we can complete your statutory invoice.
</p>

<p>
    <strong>Please respond by {{ $responseDeadline }}.</strong>
    If we do not receive verified details within this window, we will issue a B2C statutory invoice for this transaction
    in line with our verification policy.
</p>

<p>Your payment was successful. This message is only about invoice documentation.</p>
