@props(['serviceCase', 'canManageTransactions' => false, 'requiresLegacyVerification' => false, 'legacyVerificationUrl' => null, 'legacyVerificationMode' => 'customer'])

@php
    $order = $serviceCase->order;
    $isCompleted = $order?->isTransactionLocked() ?? false;
    $canAssign = $canManageTransactions
        && $order
        && ! $order->isInquiryOrder()
        && auth()->user()?->can('assignTransaction', $order);
@endphp

<td class="transaction-id-cell"
    @if($canAssign && ! $isCompleted)
        data-inline-transaction="true"
        data-order-id="{{ $order->id }}"
        data-incident-id="{{ $serviceCase->id }}"
        data-store-url="{{ route('orders.transaction.store', $order) }}"
        data-requires-legacy-verification="{{ $requiresLegacyVerification ? 'true' : 'false' }}"
        data-legacy-verification-mode="{{ $legacyVerificationMode }}"
        @if($legacyVerificationUrl)
            data-legacy-verification-url="{{ $legacyVerificationUrl }}"
        @endif
    @endif>
    @if($isCompleted && $order?->transaction_id)
        <span class="transaction-completed-display text-nowrap"
              data-bs-toggle="tooltip"
              data-dashboard-tooltip
              data-bs-placement="top">
            <i class="bi bi-check-circle-fill text-success me-1" aria-hidden="true"></i>{{ $order->transaction_id }}
        </span>
        <template class="dashboard-tooltip-template">
            {!! $order->transactionAssignTooltipHtml() !!}
        </template>
    @elseif($canAssign)
        <button type="button"
                class="transaction-cell-trigger dashboard-u-transaction-add dashboard-u-transition dashboard-u-focus-ring"
                aria-label="Add service reference"
                data-bs-toggle="tooltip"
                data-bs-placement="top"
                data-bs-title="Add service reference">
            <i class="bi bi-plus-lg" aria-hidden="true"></i>
        </button>
        <div class="transaction-inline-editor d-none">
            <div class="input-group input-group-sm">
                <input type="text"
                       class="form-control transaction-inline-input"
                       placeholder="Service Reference"
                       maxlength="100"
                       aria-label="Service Reference">
                <button type="button" class="btn btn-outline-success transaction-inline-save" aria-label="Save">
                    <i class="bi bi-check-lg"></i>
                </button>
            </div>
            <div class="invalid-feedback d-block small transaction-inline-error"></div>
        </div>
    @else
        {{ $order?->transaction_id ?: '—' }}
    @endif
</td>
