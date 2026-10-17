@php
    use App\Data\Orders\OrderPaymentBreakdown;

    $breakdown = $paymentBreakdown ?? null;
    $calculation = $calculation ?? null;
@endphp

@if($hardwareRefundBlocked ?? false)
    <div class="col-12">
        <div class="alert alert-warning border-warning mb-0" role="alert">
            <h2 class="h6 alert-heading">Hardware refund — controlled case workflow required</h2>
            <p class="mb-2 small">
                This order is classified as a hardware product sale. Refunds cannot be submitted from this form.
                Use <strong>pre-shipment cancellation</strong> (agent request → Finance review) or a
                <strong>return/exchange case</strong> (receive → inspect → case-linked refund submit).
                Finance approval and payout execution remain separate steps; wallet credit and OPM conversion are not auto-enabled here.
            </p>
            @if(($returnCasesForOrder ?? collect())->isNotEmpty())
                <p class="mb-1 small fw-semibold">Recent return cases for this order</p>
                <ul class="small mb-0">
                    @foreach($returnCasesForOrder as $case)
                        <li>
                            <a href="{{ route('return-cases.show', $case) }}">{{ $case->case_no }}</a>
                            — {{ $case->status->label() ?? $case->status }}
                            @if($case->refund_request_id)
                                (refund linked)
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
@endif

@if($breakdown instanceof OrderPaymentBreakdown)
    <div class="col-12">
        <div class="border rounded p-3 bg-white">
            <h3 class="h6 mb-2">Payment breakdown (Desk sources)</h3>
            <dl class="row mb-0 small">
                <dt class="col-6 text-muted">Tender shape</dt>
                <dd class="col-6 text-end">{{ str_replace('_', ' ', $breakdown->state) }}</dd>
                @if($breakdown->orderTotal !== null)
                    <dt class="col-6 text-muted">Order total</dt>
                    <dd class="col-6 text-end">₹{{ number_format($breakdown->orderTotal, 2) }}</dd>
                @endif
                @if($breakdown->walletUsed !== null)
                    <dt class="col-6 text-muted">Wallet tender</dt>
                    <dd class="col-6 text-end">₹{{ number_format($breakdown->walletUsed, 2) }}</dd>
                @endif
                @if($breakdown->externalPaid !== null)
                    <dt class="col-6 text-muted">External paid{{ $breakdown->externalMethodLabel ? ' ('.$breakdown->externalMethodLabel.')' : '' }}</dt>
                    <dd class="col-6 text-end">₹{{ number_format($breakdown->externalPaid, 2) }}</dd>
                @endif
            </dl>
            <p class="form-text mb-0 mt-2">
                Split-tender caps and Finance allocation use stored commerce/support payment fields — not inferred from these totals alone.
            </p>
        </div>
    </div>
@endif

@if($calculation !== null)
    <div class="col-12">
        <p class="small text-muted mb-0">
            <strong>Execution note:</strong> OPM payout remains manual where the existing executor requires evidence;
            automatic Cashfree refund execution is not offered from this form. Prior refunds reduce the maximum refundable amount shown above.
        </p>
    </div>
@endif
