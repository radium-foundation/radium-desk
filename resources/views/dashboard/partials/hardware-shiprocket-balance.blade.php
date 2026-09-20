@php
    /** @var \App\Services\Shipping\Data\ShiprocketWalletBalancePresentation|null $shiprocketBalance */
    $shiprocketBalance = $hardwareWorkspace['shiprocketBalance'] ?? null;
@endphp

@if($shiprocketBalance instanceof \App\Services\Shipping\Data\ShiprocketWalletBalancePresentation)
    @php
        $toneClass = match ($shiprocketBalance->status->uiTone()) {
            'critical' => 'dashboard-shiprocket-balance--critical',
            'warning' => 'dashboard-shiprocket-balance--warning',
            'neutral' => 'dashboard-shiprocket-balance--neutral',
            default => 'dashboard-shiprocket-balance--muted',
        };
        $checkedMinutes = $shiprocketBalance->checkedMinutesAgo();
    @endphp
    <div @class(['dashboard-shiprocket-balance', $toneClass, 'dashboard-shiprocket-balance--stale' => $shiprocketBalance->isStale])
         role="status"
         aria-live="polite"
         data-shiprocket-balance>
        <div class="dashboard-shiprocket-balance__label">Shiprocket Balance</div>
        <div class="dashboard-shiprocket-balance__value">
            @if($shiprocketBalance->showsBalanceAmount())
                ₹{{ number_format((float) $shiprocketBalance->balanceAmount, 2) }}
            @else
                Balance unavailable
            @endif
        </div>
        <div class="dashboard-shiprocket-balance__meta">
            @if($shiprocketBalance->showsBalanceAmount() && $checkedMinutes !== null)
                Checked {{ $checkedMinutes === 0 ? 'just now' : $checkedMinutes.' min ago' }}
            @elseif($shiprocketBalance->status === \App\Enums\ShiprocketWalletBalanceStatus::AuthError)
                Authentication failed
            @elseif($shiprocketBalance->status === \App\Enums\ShiprocketWalletBalanceStatus::ProviderError)
                Provider unavailable
            @else
                Balance unavailable
            @endif
            @if($shiprocketBalance->isStale)
                · Stale data
            @endif
        </div>
    </div>
@endif
