@if(!empty($cancelledRecovery) && $cancelledRecovery->applies)
    <div class="card border-secondary mb-4" id="cancelled-invoice-recovery">
        <div class="card-header bg-light">
            <h2 class="h6 mb-0">Cancelled invoice recovery (guidance only)</h2>
        </div>
        <div class="card-body">
            <p class="small text-muted mb-3">
                This panel records Finance recovery steps. It does not mint invoices, record payments, credit wallets, execute refunds, or convert to OPM.
            </p>

            <dl class="row small mb-3">
                <dt class="col-sm-3">Payment path</dt>
                <dd class="col-sm-9">{{ $cancelledRecovery->paymentPath->label() }}</dd>
                <dt class="col-sm-3">Desk received</dt>
                <dd class="col-sm-9">₹{{ number_format($cancelledRecovery->deskAmountReceived, 2) }}</dd>
                <dt class="col-sm-3">Desk outstanding</dt>
                <dd class="col-sm-9">₹{{ number_format($cancelledRecovery->deskAmountOutstanding, 2) }}</dd>
                <dt class="col-sm-3">Billable source</dt>
                <dd class="col-sm-9"><code>{{ $cancelledRecovery->billableSourceSummary }}</code></dd>
            </dl>

            @if($cancelledRecovery->requiresExternalPaymentVerification())
                <div class="alert alert-warning py-2 small" role="status">
                    Verify payment with bank/gateway evidence before treating this as paid. Desk “Unpaid” does not prove the customer did not pay elsewhere.
                </div>
            @endif

            <p class="fw-semibold small mb-1">Replacement invoice</p>
            @if($cancelledRecovery->replacementMintAllowed)
                <p class="small text-success mb-2">Replacement mint is permitted by current checks.</p>
            @else
                <ul class="small mb-2">
                    @foreach($cancelledRecovery->replacementBlockers as $blocker)
                        <li>{{ $blocker }}</li>
                    @endforeach
                </ul>
            @endif
            @if($cancelledRecovery->issueEntryHint)
                <p class="small text-muted mb-3">{{ $cancelledRecovery->issueEntryHint }}</p>
            @endif

            @if($cancelledRecovery->paymentPath === \App\Enums\StatutoryInvoiceCancelledRecoveryPaymentPath::PaidDeskAllocations
                || $cancelledRecovery->paymentPath === \App\Enums\StatutoryInvoiceCancelledRecoveryPaymentPath::PaidLegacyInvoiceFields)
                <p class="fw-semibold small mb-1">Paid path — Finance/CA decisions</p>
                <ul class="small mb-3">
                    @foreach($cancelledRecovery->paidFinanceDecisions as $step)
                        <li>{{ $step }}</li>
                    @endforeach
                </ul>
            @else
                <p class="fw-semibold small mb-1">Unpaid path — next steps</p>
                <ul class="small mb-3">
                    @foreach($cancelledRecovery->unpaidNextSteps as $step)
                        <li>{{ $step }}</li>
                    @endforeach
                </ul>
            @endif

            @if($refundReview)
                <p class="fw-semibold small mb-1">Refund review (read-only)</p>
                <p class="small mb-2">{{ $refundReview->message }}</p>
            @endif

            @if(!empty($cancelledRecovery->separationReminders))
                <p class="fw-semibold small mb-1">Lifecycle separation</p>
                <ul class="small mb-3">
                    @foreach($cancelledRecovery->separationReminders as $reminder)
                        <li>{{ $reminder }}</li>
                    @endforeach
                </ul>
            @endif

            @if(!$cancelledRecovery->walletToOpmSupportedOnMain)
                <p class="small text-muted mb-0">
                    Wallet→OPM is not available on this deployment (schema/routes incomplete). Return-allocation OPM remains a separate, flag-gated workflow.
                </p>
            @endif
        </div>
    </div>
@endif
