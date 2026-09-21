<?php

namespace App\Services\Purchasing;

use App\Models\PurchaseOrder;
use App\Models\ReferenceSequence;
use App\Services\OperationalReferenceSequenceService;
use App\Services\StatutoryInvoice\StatutoryFinancialYear;
use App\Support\OperationalReference\OperationalReferenceParser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Allocates independent purchase order references (PO-671+ for FY 2026-27).
 *
 * Format: PO-{FY code}{running serial}, e.g. FY 2026-27 → PO-671, PO-672.
 * Legacy formats such as PO-2026-00001 and PO-07-001 are never rewritten.
 */
class PurchaseOrderReferenceService
{
    public function __construct(
        private readonly OperationalReferenceSequenceService $sequences,
    ) {}

    public function allocate(?Carbon $at = null): string
    {
        $financialYear = StatutoryFinancialYear::containing($at ?? now());
        $this->ensureSequenceInitialized($financialYear);

        return $this->sequences->allocate(
            ReferenceSequence::purchaseOrderSequenceName($financialYear),
            $this->floorForFinancialYear($financialYear),
            'PO-',
        );
    }

    public function peekNext(?Carbon $at = null): int
    {
        $financialYear = StatutoryFinancialYear::containing($at ?? now());
        $this->ensureSequenceInitialized($financialYear);

        return $this->sequences->peekNext(
            ReferenceSequence::purchaseOrderSequenceName($financialYear),
            $this->floorForFinancialYear($financialYear),
        );
    }

    public function floorForFinancialYear(StatutoryFinancialYear $financialYear): int
    {
        return (int) ($financialYear->code().'1');
    }

    private function ensureSequenceInitialized(StatutoryFinancialYear $financialYear): void
    {
        if (! Schema::hasTable('reference_sequences')) {
            return;
        }

        $sequenceName = ReferenceSequence::purchaseOrderSequenceName($financialYear);

        if (DB::table('reference_sequences')->where('name', $sequenceName)->exists()) {
            return;
        }

        $floor = $this->floorForFinancialYear($financialYear);
        $currentValue = $floor - 1;

        if (Schema::hasTable('purchase_orders')) {
            PurchaseOrder::query()
                ->pluck('po_number')
                ->each(function (string $reference) use (&$currentValue, $financialYear): void {
                    $parsed = OperationalReferenceParser::parsePurchaseOrderOperationalValue($reference, $financialYear);
                    if ($parsed !== null) {
                        $currentValue = max($currentValue, $parsed);
                    }
                });
        }

        $now = now();

        DB::table('reference_sequences')->insert([
            'name' => $sequenceName,
            'current_value' => $currentValue,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
