@php
    $state = $ledger['state'] ?? 'unavailable';
    $wallet = is_array($ledger['wallet'] ?? null) ? $ledger['wallet'] : null;
    $transactions = $ledger['transactions'] ?? [];
    $pagination = $ledger['pagination'] ?? [];
    $filters = $filters ?? [];
    $typeClass = [
        'credit' => 'text-bg-success',
        'debit' => 'text-bg-danger',
        'reversal' => 'text-bg-warning',
    ];
@endphp

<div class="customer-360-wallet-ledger" data-wallet-ledger-root>
    @if($state === 'unresolved')
        <div class="alert alert-warning mb-0" role="status">
            This case is not linked to one verified Desk customer, so the Central Wallet is not shown.
        </div>
    @elseif($state !== 'ready' || $wallet === null)
        <div class="alert alert-danger mb-0" role="alert">
            Central Wallet is temporarily unavailable. The balance is not shown.
        </div>
    @else
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                    <div>
                        <h2 class="h6 mb-1">Central Wallet</h2>
                        <p class="display-6 mb-1">₹{{ number_format((float) $wallet['available'], 2) }}</p>
                        <p class="text-muted small mb-0">One customer wallet. The website on each row is the source of that transaction.</p>
                    </div>
                    <div class="text-md-end">
                        <p class="small text-muted mb-1">Wallet ID</p>
                        <p class="fw-semibold mb-2"><code>{{ $wallet['masked_id'] }}</code></p>
                        <span class="badge text-bg-light border">{{ $wallet['status'] }}</span>
                        <span class="badge text-bg-light border">{{ $wallet['source'] }}</span>
                    </div>
                </div>
                <div class="border rounded p-2 small mt-3">
                    <div class="text-muted">Active reservations</div>
                    <div class="fw-semibold">₹{{ number_format((float) $wallet['reserved'], 2) }}</div>
                </div>
            </div>
        </div>

        <p class="small text-danger mb-2 d-none" data-wallet-ledger-error role="alert"></p>

        <form method="GET"
              action="{{ $loadMoreUrl }}"
              class="row g-2 align-items-end mb-3"
              data-wallet-ledger-filter-form>
            <div class="col-md-3">
                <label class="form-label small" for="wallet-ledger-type">Type</label>
                <select id="wallet-ledger-type" name="type" class="form-select form-select-sm">
                    @foreach(['all' => 'All', 'credit' => 'Credits', 'debit' => 'Debits', 'reversal' => 'Reversals'] as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['type'] ?? 'all') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label small" for="wallet-ledger-reference">Business reference</label>
                <input id="wallet-ledger-reference"
                       type="text"
                       name="business_reference"
                       class="form-control form-control-sm"
                       value="{{ $filters['business_reference'] ?? '' }}"
                       maxlength="191">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-sm btn-primary w-100">Apply</button>
            </div>
        </form>

        @if(!empty($ledger['empty_message']))
            <div class="alert alert-light border mb-3">{{ $ledger['empty_message'] }}</div>
        @endif

        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                    <tr>
                        <th>Date (IST)</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Website</th>
                        <th>Business reference</th>
                        <th>Source reference</th>
                        <th>Ledger</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($transactions as $row)
                        <tr>
                            <td>{{ $row['posted_at'] ?? '—' }}</td>
                            <td>
                                <span class="badge {{ $typeClass[$row['entry_type']] ?? 'text-bg-secondary' }}">
                                    {{ $row['entry_type'] }}
                                </span>
                            </td>
                            <td>₹{{ number_format((float) $row['amount'], 2) }}</td>
                            <td><span class="badge text-bg-light border">{{ $row['source_label'] }}</span></td>
                            <td>
                                @if(!empty($row['business_reference']))
                                    @if(!empty($row['desk_order_id']))
                                        <a href="{{ route('orders.show', $row['desk_order_id']) }}">{{ $row['business_reference'] }}</a>
                                    @elseif(!empty($row['can_view_refund']) && !empty($row['desk_refund_id']))
                                        <a href="{{ route('refunds.show', $row['desk_refund_id']) }}">{{ $row['business_reference'] }}</a>
                                    @else
                                        {{ $row['business_reference'] }}
                                    @endif
                                @else
                                    —
                                @endif
                                @if(!empty($row['reservation_id']))
                                    <div class="text-muted small">Reservation {{ $row['reservation_id'] }}</div>
                                @endif
                                @if(!empty($row['original_ledger_entry_id']))
                                    <div class="text-muted small">Reverses #{{ $row['original_ledger_entry_id'] }}</div>
                                @endif
                            </td>
                            <td>{{ $row['source_reference'] ?: '—' }}</td>
                            <td><code>#{{ $row['ledger_entry_id'] }}</code></td>
                            <td>{{ $row['status'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-muted">No posted Central Wallet transactions for the current filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if(!empty($pagination['has_more']) && !empty($pagination['next_cursor']))
            <div class="d-flex justify-content-center">
                <button type="button"
                        class="btn btn-outline-secondary btn-sm"
                        data-wallet-ledger-load-more
                        data-next-cursor="{{ $pagination['next_cursor'] }}"
                        data-load-url="{{ $loadMoreUrl }}">
                    Load more
                </button>
            </div>
        @endif
    @endif
</div>
