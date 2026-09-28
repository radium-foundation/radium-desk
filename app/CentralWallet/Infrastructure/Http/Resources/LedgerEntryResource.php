<?php

namespace App\CentralWallet\Infrastructure\Http\Resources;

use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;

final class LedgerEntryResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(CentralWalletLedgerEntry $entry): array
    {
        $data = [
            'ledger_entry_id' => $entry->id,
            'central_wallet_id' => $entry->central_wallet_id,
            'entry_type' => $entry->entry_type->value,
            'amount' => (string) $entry->amount,
            'currency' => $entry->currency,
            'status' => $entry->status->value,
            'source_system' => $entry->source_system,
            'source_reference' => $entry->source_reference,
            'correlation_id' => $entry->correlation_id,
            'posted_at' => $entry->posted_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'created_at' => $entry->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ];

        if ($entry->business_reference !== null) {
            $data['business_reference'] = $entry->business_reference;
        }

        if ($entry->reservation_id !== null) {
            $data['reservation_id'] = $entry->reservation_id;
        }

        return $data;
    }
}
