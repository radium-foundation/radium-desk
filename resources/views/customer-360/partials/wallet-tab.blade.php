@php
    $state = $ledger['state'] ?? 'unavailable';
    $wallet = is_array($ledger['wallet'] ?? null) ? $ledger['wallet'] : null;
    $transactions = $ledger['transactions'] ?? [];
    $pagination = $ledger['pagination'] ?? [];
    $filters = $filters ?? [];
    $shortIdentifier = function (?string $value): string {
        $value = trim((string) $value);
        if ($value === '' || strlen($value) <= 22) {
            return $value;
        }

        return substr($value, 0, 8).'…'.substr($value, -4);
    };
    $amountPrefix = [
        'credit' => '+',
        'debit' => '−',
        'reversal' => '+',
    ];
@endphp

<div class="c360-wallet customer-360-wallet-ledger" data-wallet-ledger-root data-c360-wallet-layout="compact">
    @if($state === 'unresolved')
        <div class="alert alert-warning mb-0" role="status">
            This case is not linked to one verified Desk customer, so the Central Wallet is not shown.
        </div>
    @elseif($state !== 'ready' || $wallet === null)
        <div class="alert alert-danger mb-0" role="alert">
            Central Wallet is temporarily unavailable. The balance is not shown.
        </div>
    @else
        <header class="c360-wallet-head">
            <div>
                <p class="c360-wallet-kicker">Central Wallet</p>
                <p class="c360-wallet-balance">₹{{ number_format((float) $wallet['available'], 2) }}</p>
                <p class="c360-wallet-note">One customer wallet</p>
                <p class="c360-wallet-note">Active reservations ₹{{ number_format((float) $wallet['reserved'], 2) }}</p>
            </div>
            <div class="c360-wallet-aside">
                <div>Wallet ID <code>{{ $wallet['masked_id'] }}</code></div>
                <div>{{ $wallet['status'] }} · {{ $wallet['source'] }}</div>
            </div>
        </header>

        <p class="small text-danger mb-0 d-none" data-wallet-ledger-error role="alert"></p>

        <form method="GET"
              action="{{ $loadMoreUrl }}"
              class="c360-wallet-filters"
              data-wallet-ledger-filter-form>
            <div>
                <label class="c360-wallet-label" for="wallet-ledger-type">Type</label>
                <select id="wallet-ledger-type" name="type" class="form-select form-select-sm">
                    @foreach(['all' => 'All', 'credit' => 'Credits', 'debit' => 'Debits', 'reversal' => 'Reversals'] as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['type'] ?? 'all') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="c360-wallet-label" for="wallet-ledger-reference">Business reference</label>
                <input id="wallet-ledger-reference"
                       type="text"
                       name="business_reference"
                       class="form-control form-control-sm"
                       value="{{ $filters['business_reference'] ?? '' }}"
                       maxlength="191">
            </div>
            <button type="submit" class="btn btn-sm btn-primary">Apply</button>
        </form>

        @if(!empty($ledger['empty_message']))
            <div class="alert alert-light border mb-0">{{ $ledger['empty_message'] }}</div>
        @endif

        <ul class="c360-wallet-list" data-wallet-ledger-list>
            @foreach($transactions as $row)
                @php
                    $entryType = (string) ($row['entry_type'] ?? '');
                    $businessReference = (string) ($row['business_reference'] ?? '');
                    $sourceReference = (string) ($row['source_reference'] ?? '');
                    $reservationId = (string) ($row['reservation_id'] ?? '');
                    $correlationId = (string) ($row['correlation_id'] ?? '');
                @endphp
                <li class="c360-wallet-entry" data-wallet-ledger-entry data-entry-type="{{ $entryType }}">
                    <div class="c360-wallet-line">
                        <span class="c360-wallet-type">{{ $entryType }}</span>
                        <span class="c360-wallet-amount c360-wallet-amount--{{ $entryType }}">{{ $amountPrefix[$entryType] ?? '' }}₹{{ number_format((float) $row['amount'], 2) }}</span>
                        <span class="c360-wallet-ref" title="{{ $businessReference }}">
                            @if($businessReference !== '')
                                @if(!empty($row['desk_order_id']))
                                    <a href="{{ route('orders.show', $row['desk_order_id']) }}">{{ $businessReference }}</a>
                                @elseif(!empty($row['can_view_refund']) && !empty($row['desk_refund_id']))
                                    <a href="{{ route('refunds.show', $row['desk_refund_id']) }}">{{ $businessReference }}</a>
                                @else
                                    {{ $businessReference }}
                                @endif
                            @else
                                —
                            @endif
                        </span>
                    </div>
                    <div class="c360-wallet-sub">
                        <span class="c360-wallet-site" title="{{ $row['source_system'] }}">{{ $row['source_label'] }}</span>
                        <time datetime="{{ $row['posted_at'] }}">{{ $row['posted_at'] ?? '—' }}</time>
                        <span class="c360-wallet-ledger-id">#{{ $row['ledger_entry_id'] }}</span>
                    </div>
                    <details class="c360-wallet-more">
                        <summary>Details</summary>
                        <dl class="c360-wallet-facts">
                            <dt>Ledger</dt>
                            <dd>#{{ $row['ledger_entry_id'] }}</dd>
                            <dt>Website</dt>
                            <dd>{{ $row['source_system'] }}</dd>
                            <dt>Business reference</dt>
                            <dd>{{ $businessReference !== '' ? $businessReference : '—' }}</dd>
                            <dt>Source reference</dt>
                            <dd>
                                @if($sourceReference !== '')
                                    <x-customer-360-inline-copy :value="$sourceReference" label="Source reference" copy-key="source-reference">
                                        {{ $shortIdentifier($sourceReference) }}
                                    </x-customer-360-inline-copy>
                                @else
                                    —
                                @endif
                            </dd>
                            <dt>Reservation</dt>
                            <dd>
                                @if($reservationId !== '')
                                    <x-customer-360-inline-copy :value="$reservationId" label="Reservation ID" copy-key="reservation-id">
                                        {{ $shortIdentifier($reservationId) }}
                                    </x-customer-360-inline-copy>
                                @else
                                    —
                                @endif
                            </dd>
                            <dt>Correlation</dt>
                            <dd>
                                @if($correlationId !== '')
                                    <x-customer-360-inline-copy :value="$correlationId" label="Correlation ID" copy-key="correlation-id">
                                        {{ $shortIdentifier($correlationId) }}
                                    </x-customer-360-inline-copy>
                                @else
                                    —
                                @endif
                            </dd>
                            <dt>Reversal</dt>
                            <dd>{{ !empty($row['original_ledger_entry_id']) ? 'Reverses #'.$row['original_ledger_entry_id'] : '—' }}</dd>
                            <dt>Status</dt>
                            <dd>{{ $row['status'] }}</dd>
                            <dt>Currency</dt>
                            <dd>{{ $row['currency'] }}</dd>
                            <dt>Posted</dt>
                            <dd>{{ $row['posted_at'] ?? '—' }}</dd>
                        </dl>
                    </details>
                </li>
            @endforeach
        </ul>

        @if($transactions === [])
            <p class="c360-wallet-empty">No posted Central Wallet transactions for the current filters.</p>
        @endif

        @if(!empty($pagination['has_more']) && !empty($pagination['next_cursor']))
            <div class="c360-wallet-more-page">
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
