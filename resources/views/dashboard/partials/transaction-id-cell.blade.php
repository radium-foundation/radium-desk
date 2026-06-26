@props(['serviceCase', 'canManageTransactions' => false])

@php
    $order = $serviceCase->order;
    $isCompleted = $order?->isTransactionLocked() ?? false;
    $canAssign = $canManageTransactions && $order && auth()->user()?->can('assignTransaction', $order);
@endphp

<td class="transaction-id-cell d-none d-md-table-cell"
    @if($canAssign && ! $isCompleted)
        data-inline-transaction="true"
        data-order-id="{{ $order->id }}"
        data-incident-id="{{ $serviceCase->id }}"
        data-store-url="{{ route('orders.transaction.store', $order) }}"
    @endif>
    @if($isCompleted && $order?->transaction_id)
        <span class="transaction-completed-display text-nowrap"
              data-bs-toggle="tooltip"
              data-bs-placement="top"
              data-bs-html="true"
              data-bs-title="{!! $order->transactionAssignTooltipHtml() !!}">
            <i class="bi bi-check-circle-fill text-success me-1" aria-hidden="true"></i>{{ $order->transaction_id }}
        </span>
    @elseif($canAssign)
        <button type="button"
                class="btn btn-link btn-sm p-0 text-muted text-decoration-none transaction-cell-trigger">
            Click to add
        </button>
        <div class="transaction-inline-editor d-none">
            <div class="input-group input-group-sm">
                <input type="text"
                       class="form-control transaction-inline-input"
                       placeholder="Transaction ID"
                       maxlength="100"
                       aria-label="Transaction ID">
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
