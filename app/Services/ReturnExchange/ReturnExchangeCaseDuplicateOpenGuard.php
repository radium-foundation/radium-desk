<?php

namespace App\Services\ReturnExchange;

use App\Enums\ReturnExchangeCaseStatus;
use App\Models\ReturnExchangeCase;
use App\Models\ReturnExchangeCaseLine;
use App\Support\Inventory\InventorySerialNumber;
use Illuminate\Validation\ValidationException;

final class ReturnExchangeCaseDuplicateOpenGuard
{
    public function assertNoDuplicateOpenCase(int $inventorySaleId, string $returnSerialNumber): void
    {
        $normalized = InventorySerialNumber::normalize(trim($returnSerialNumber));
        if ($normalized === '') {
            return;
        }

        $openCaseIds = ReturnExchangeCase::query()
            ->where('inventory_sale_id', $inventorySaleId)
            ->whereNotIn('status', [
                ReturnExchangeCaseStatus::Closed->value,
            ])
            ->pluck('id');

        if ($openCaseIds->isEmpty()) {
            return;
        }

        $conflict = ReturnExchangeCaseLine::query()
            ->whereIn('return_exchange_case_id', $openCaseIds)
            ->get()
            ->contains(function (ReturnExchangeCaseLine $line) use ($normalized): bool {
                $lineSerial = InventorySerialNumber::normalize((string) ($line->serial_number ?? ''));

                return $lineSerial === $normalized;
            });

        if ($conflict) {
            throw ValidationException::withMessages([
                'lines.0.serial_number' => 'An open return case already exists for this POS sale and serial.',
            ]);
        }
    }
}
