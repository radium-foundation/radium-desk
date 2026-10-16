<?php

namespace App\Services\WalletRefundOpmConversion\Adapters;

use App\Enums\WalletRefundCreditSource;
use App\Models\RefundRequest;
use App\Models\WalletRefundOpmConversion;
use App\Services\WalletRefundOpmConversion\Contracts\WalletRefundOpmSiteAdapter;
use App\Services\WalletRefundOpmConversion\LegacySpokeWalletRefundOpmClient;

class RadiumBoxWalletRefundOpmAdapter implements WalletRefundOpmSiteAdapter
{
    public function __construct(
        private readonly LegacySpokeWalletRefundOpmClient $client,
    ) {}

    public function siteCode(): string
    {
        return 'radiumbox.com';
    }

    public function isEnabled(): bool
    {
        return (bool) config('wallet_refund_opm_conversion.sites.radiumbox.com.enabled', false);
    }

    public function supportsCreditSource(WalletRefundCreditSource $source): bool
    {
        return $source === WalletRefundCreditSource::LegacyLocal;
    }

    public function reserve(WalletRefundOpmConversion $conversion, RefundRequest $refund): array
    {
        $refund->loadMissing('order');
        $data = $this->client->post('wallet-opm-conversion-reservations', $this->reservationBody($conversion, $refund), $this->spokeConfig());

        return [
            'reservation_id' => (string) data_get($data, 'data.reservation_id'),
            'status' => (string) data_get($data, 'data.status', 'reserved'),
            'idempotent_replay' => (bool) data_get($data, 'data.idempotent_replay', false),
        ];
    }

    public function confirmReservation(WalletRefundOpmConversion $conversion): array
    {
        $data = $this->client->post(
            'wallet-opm-conversion-reservations/'.$conversion->site_reservation_id.'/confirm',
            ['source_system' => 'radium_desk'],
            $this->spokeConfig(),
        );

        return [
            'reservation_id' => (string) data_get($data, 'data.reservation_id', $conversion->site_reservation_id),
            'status' => (string) data_get($data, 'data.status', 'reserved'),
        ];
    }

    public function finalizeWalletReversal(WalletRefundOpmConversion $conversion, RefundRequest $refund): array
    {
        $refund->loadMissing('order');
        $data = $this->client->post(
            'wallet-opm-conversion-reservations/'.$conversion->site_reservation_id.'/finalize-reversal',
            [
                'source_system' => 'radium_desk',
                'desk_conversion_id' => $conversion->public_id,
                'desk_refund_reference' => $refund->reference_no,
                'order_id' => $refund->order?->order_id,
                'amount' => (string) $conversion->amount,
                'currency' => $conversion->currency,
                'idempotency_key' => $conversion->wallet_finalization_idempotency_key,
                'customer_email' => $refund->order?->customer_email,
            ],
            $this->spokeConfig(),
        );

        return [
            'wallet_reversal_reference' => (string) data_get($data, 'data.wallet_reversal_reference', ''),
            'idempotent_replay' => (bool) data_get($data, 'data.idempotent_replay', false),
        ];
    }

    public function releaseReservation(WalletRefundOpmConversion $conversion, RefundRequest $refund): void
    {
        $this->client->post(
            'wallet-opm-conversion-reservations/'.$conversion->site_reservation_id.'/release',
            [
                'source_system' => 'radium_desk',
                'desk_conversion_id' => $conversion->public_id,
                'release_reason' => 'desk_authorized_release',
            ],
            $this->spokeConfig(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function reservationBody(WalletRefundOpmConversion $conversion, RefundRequest $refund): array
    {
        return [
            'source_system' => 'radium_desk',
            'desk_conversion_id' => $conversion->public_id,
            'desk_refund_reference' => $refund->reference_no,
            'order_id' => $refund->order?->order_id,
            'amount' => (string) $conversion->amount,
            'currency' => $conversion->currency,
            'idempotency_key' => 'reserve:'.$conversion->public_id,
            'customer_email' => $refund->order?->customer_email,
            'wallet_credit_source' => $conversion->wallet_credit_source?->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function spokeConfig(): array
    {
        return (array) config('order_lookup.spokes.radiumbox_com', []);
    }
}
