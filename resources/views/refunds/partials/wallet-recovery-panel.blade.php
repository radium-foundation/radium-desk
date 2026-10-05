@if(!empty($walletRefundRecovery))
    <div class="alert alert-warning border mb-3">
        <div class="fw-semibold mb-1">Wallet credit already completed</div>
        <p class="small mb-2">
            A matching Central Wallet credit exists for this refund. No additional credit will be created.
        </p>
        <ul class="small mb-0">
            <li>Amount: <strong>₹{{ number_format((float) $walletRefundRecovery['amount'], 2) }}</strong></li>
            <li>Transaction: <strong>{{ $walletRefundRecovery['wallet_reference'] }}</strong></li>
        </ul>
    </div>
@endif
