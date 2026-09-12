@php
    $balance = $ledger['balance'] ?? [];
    $transactions = $ledger['transactions'] ?? [];
    $pagination = $ledger['pagination'] ?? [];
    $filters = $filters ?? [];
@endphp

<div class="customer-360-wallet-ledger" data-wallet-ledger-root>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                <div>
                    <h2 class="h6 mb-1">Current Wallet Balance</h2>
                    <p class="display-6 mb-0">₹{{ number_format((float) ($balance['available'] ?? 0), 2) }}</p>
                    <p class="text-muted small mb-0">Canonical balance from successful credits minus successful debits.</p>
                </div>
                <div class="text-end">
                    <p class="small text-muted mb-1">Last wallet activity</p>
                    <p class="fw-semibold mb-0">{{ $ledger['last_activity_at'] ?? '—' }}</p>
                </div>
            </div>

            <div class="row g-2 mt-3">
                <div class="col-md-4">
                    <div class="border rounded p-2 small">
                        <div class="text-muted">Pending credits</div>
                        <div class="fw-semibold">₹{{ number_format((float) ($balance['pending_credits'] ?? 0), 2) }}</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="border rounded p-2 small">
                        <div class="text-muted">Pending debits</div>
                        <div class="fw-semibold">₹{{ number_format((float) ($balance['pending_debits'] ?? 0), 2) }}</div>
                    </div>
                </div>
                @if(isset($balance['cached_wallet_amount']))
                    <div class="col-md-4">
                        <div class="border rounded p-2 small">
                            <div class="text-muted">Cached wallet amount (diagnostic)</div>
                            <div class="fw-semibold">₹{{ number_format((float) $balance['cached_wallet_amount'], 2) }}</div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    @if(!empty($ledger['empty_message']))
        <div class="alert alert-light border mb-3">{{ $ledger['empty_message'] }}</div>
    @endif

    <form method="GET"
          action="{{ $loadMoreUrl }}"
          class="row g-2 align-items-end mb-3"
          data-wallet-ledger-filter-form>
        <div class="col-md-2">
            <label class="form-label small">Type</label>
            <select name="type" class="form-select form-select-sm">
                @foreach(['all' => 'All', 'credit' => 'Credits', 'debit' => 'Debits'] as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['type'] ?? 'all') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small">Status</label>
            <input type="text" name="status" class="form-control form-control-sm" value="{{ $filters['status'] ?? '' }}" placeholder="success">
        </div>
        <div class="col-md-2">
            <label class="form-label small">Order</label>
            <input type="text" name="order_code" class="form-control form-control-sm" value="{{ $filters['order_code'] ?? '' }}">
        </div>
        <div class="col-md-2">
            <label class="form-label small">Refund ref</label>
            <input type="text" name="desk_refund_reference" class="form-control form-control-sm" value="{{ $filters['desk_refund_reference'] ?? '' }}">
        </div>
        <div class="col-md-2">
            <label class="form-label small">Wallet ref</label>
            <input type="text" name="reference" class="form-control form-control-sm" value="{{ $filters['reference'] ?? '' }}">
        </div>
        <div class="col-md-2">
            <label class="form-label small">From</label>
            <input type="date" name="date_from" class="form-control form-control-sm" value="{{ $filters['date_from'] ?? '' }}">
        </div>
        <div class="col-md-2">
            <label class="form-label small">To</label>
            <input type="date" name="date_to" class="form-control form-control-sm" value="{{ $filters['date_to'] ?? '' }}">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-sm btn-primary w-100">Apply</button>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead>
                <tr>
                    <th>Date (IST)</th>
                    <th>Type</th>
                    <th>Credit</th>
                    <th>Debit</th>
                    <th>Order</th>
                    <th>Refund</th>
                    <th>Reference</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($transactions as $row)
                    <tr>
                        <td>{{ $row['created_at'] ?? '—' }}</td>
                        <td>
                            <span class="badge text-bg-{{ $row['type'] === 'CREDIT' ? 'success' : ($row['type'] === 'DEBIT' ? 'danger' : 'secondary') }}">
                                {{ $row['type'] }}
                            </span>
                        </td>
                        <td>{{ $row['credit'] !== null ? '₹'.number_format((float) $row['credit'], 2) : '—' }}</td>
                        <td>{{ $row['debit'] !== null ? '₹'.number_format((float) $row['debit'], 2) : '—' }}</td>
                        <td>
                            @if(!empty($row['order_code']))
                                @if(!empty($row['desk_order_id']))
                                    <a href="{{ route('orders.show', $row['desk_order_id']) }}">{{ $row['order_code'] }}</a>
                                @else
                                    <span>{{ $row['order_code'] }}</span>
                                @endif
                            @else
                                <span class="text-muted">Unmatched / Missing order link</span>
                            @endif
                        </td>
                        <td>
                            @if(!empty($row['desk_refund_reference']))
                                @if(!empty($row['can_view_refund']) && !empty($row['desk_refund_id']))
                                    <a href="{{ route('refunds.show', $row['desk_refund_id']) }}">{{ $row['desk_refund_reference'] }}</a>
                                @else
                                    {{ $row['desk_refund_reference'] }}
                                @endif
                            @else
                                —
                            @endif
                        </td>
                        <td><code>{{ $row['reference'] }}</code></td>
                        <td>
                            <div>{{ $row['status'] }}</div>
                            @if(!empty($row['badges']))
                                <div class="d-flex flex-wrap gap-1 mt-1">
                                    @foreach($row['badges'] as $badge)
                                        <span class="badge text-bg-light border">{{ $badge }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-muted">No wallet transactions found for the current filters.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if(!empty($pagination['has_more']) && !empty($pagination['next_before_id']))
        <div class="d-flex justify-content-center">
            <button type="button"
                    class="btn btn-outline-secondary btn-sm"
                    data-wallet-ledger-load-more
                    data-next-before-id="{{ $pagination['next_before_id'] }}"
                    data-load-url="{{ $loadMoreUrl }}">
                Load more
            </button>
        </div>
    @endif
</div>
