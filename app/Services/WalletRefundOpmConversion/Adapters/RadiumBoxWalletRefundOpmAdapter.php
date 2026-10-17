<?php

namespace App\Services\WalletRefundOpmConversion\Adapters;

use App\Enums\WalletRefundCreditSource;
use App\Services\WalletRefundOpmConversion\Contracts\WalletRefundOpmSiteAdapter;
use App\Services\WalletRefundOpmConversion\Contracts\WalletRefundOpmSpokeConversion;
use App\Services\WalletRefundOpmConversion\Contracts\WalletRefundOpmSpokeSubject;
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

    public function reserve(WalletRefundOpmSpokeConversion $conversion, WalletRefundOpmSpokeSubject $subject): array
    {
        $data = $this->client->post('wallet-opm-conversion-reservations', $this->reservationBody($conversion, $subject), $this->spokeConfig());

        return [
            'reservation_id' => (string) data_get($data, 'data.reservation_id'),
            'status' => (string) data_get($data, 'data.status', 'reserved'),
            'idempotent_replay' => (bool) data_get($data, 'data.idempotent_replay', false),
        ];
    }

    public function confirmReservation(WalletRefundOpmSpokeConversion $conversion): array
    {
        $data = $this->client->post(
            'wallet-opm-conversion-reservations/'.$conversion->getSiteReservationId().'/confirm',
            ['source_system' => 'radium_desk'],
            $this->spokeConfig(),
        );

        return [
            'reservation_id' => (string) data_get($data, 'data.reservation_id', $conversion->getSiteReservationId()),
            'status' => (string) data_get($data, 'data.status', 'reserved'),
        ];
    }

    public function finalizeWalletReversal(WalletRefundOpmSpokeConversion $conversion, WalletRefundOpmSpokeSubject $subject): array
    {
        $data = $this->client->post(
            'wallet-opm-conversion-reservations/'.$conversion->getSiteReservationId().'/finalize-reversal',
            [
                'source_system' => 'radium_desk',
                'desk_conversion_id' => $conversion->getPublicId(),
                'desk_refund_reference' => $subject->deskRefundReference(),
                'order_id' => $subject->order()?->order_id,
                'amount' => $conversion->getAmount(),
                'currency' => $conversion->getCurrency(),
                'idempotency_key' => $conversion->getWalletFinalizationIdempotencyKey(),
                'customer_email' => $subject->order()?->customer_email,
            ],
            $this->spokeConfig(),
        );

        return [
            'wallet_reversal_reference' => (string) data_get($data, 'data.wallet_reversal_reference', ''),
            'idempotent_replay' => (bool) data_get($data, 'data.idempotent_replay', false),
        ];
    }

    public function releaseReservation(WalletRefundOpmSpokeConversion $conversion, WalletRefundOpmSpokeSubject $subject): array
    {
        $data = $this->client->post(
            'wallet-opm-conversion-reservations/'.$conversion->getSiteReservationId().'/release',
            [
                'source_system' => 'radium_desk',
                'desk_conversion_id' => $conversion->getPublicId(),
                'release_reason' => 'desk_authorized_release',
            ],
            $this->spokeConfig(),
        );

        return [
            'idempotent_replay' => (bool) data_get($data, 'data.idempotent_replay', false),
            'status' => (string) data_get($data, 'data.status', 'released'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function reservationBody(WalletRefundOpmSpokeConversion $conversion, WalletRefundOpmSpokeSubject $subject): array
    {
        return [
            'source_system' => 'radium_desk',
            'desk_conversion_id' => $conversion->getPublicId(),
            'desk_refund_reference' => $subject->deskRefundReference(),
            'order_id' => $subject->order()?->order_id,
            'amount' => $conversion->getAmount(),
            'currency' => $conversion->getCurrency(),
            'idempotency_key' => 'reserve:'.$conversion->getPublicId(),
            'customer_email' => $subject->order()?->customer_email,
            'wallet_credit_source' => $conversion->getWalletCreditSource()?->value,
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
