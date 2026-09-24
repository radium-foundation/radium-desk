<?php

namespace App\Services\StatutoryInvoice\Data;

use App\Models\StatutoryInvoice;

final class StatutoryInvoiceCancellationResult
{
    /**
     * @param  array<string, mixed>  $irnAction
     * @param  array<string, mixed>  $inventoryAction
     * @param  array<string, mixed>  $creditNoteAction
     */
    public function __construct(
        public readonly StatutoryInvoice $invoice,
        public readonly bool $idempotent,
        public readonly array $irnAction,
        public readonly array $inventoryAction,
        public readonly array $creditNoteAction,
        public readonly ?int $cancellationRecordId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $irnAction
     * @param  array<string, mixed>  $inventoryAction
     * @param  array<string, mixed>  $creditNoteAction
     */
    public static function completed(
        StatutoryInvoice $invoice,
        array $irnAction,
        array $inventoryAction,
        array $creditNoteAction,
        ?int $cancellationRecordId = null,
    ): self {
        return new self(
            invoice: $invoice,
            idempotent: false,
            irnAction: $irnAction,
            inventoryAction: $inventoryAction,
            creditNoteAction: $creditNoteAction,
            cancellationRecordId: $cancellationRecordId,
        );
    }

    /**
     * @param  array<string, mixed>  $irnAction
     * @param  array<string, mixed>  $inventoryAction
     * @param  array<string, mixed>  $creditNoteAction
     */
    public static function idempotent(
        StatutoryInvoice $invoice,
        array $irnAction = [],
        array $inventoryAction = [],
        array $creditNoteAction = [],
        ?int $cancellationRecordId = null,
    ): self {
        return new self(
            invoice: $invoice,
            idempotent: true,
            irnAction: $irnAction,
            inventoryAction: $inventoryAction,
            creditNoteAction: $creditNoteAction,
            cancellationRecordId: $cancellationRecordId,
        );
    }
}
