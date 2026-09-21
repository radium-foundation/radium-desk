<?php

use App\Models\PurchaseOrder;
use App\Models\ReferenceSequence;
use App\Services\StatutoryInvoice\StatutoryFinancialYear;
use App\Support\OperationalReference\OperationalReferenceParser;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reference_sequences')) {
            return;
        }

        $financialYear = StatutoryFinancialYear::containing(now());
        $sequenceName = ReferenceSequence::purchaseOrderSequenceName($financialYear);
        $floor = (int) ($financialYear->code().'1');
        $now = now();

        DB::table('reference_sequences')->updateOrInsert(
            ['name' => $sequenceName],
            [
                'current_value' => Schema::hasTable('purchase_orders')
                    ? $this->initialPurchaseOrderCounterValue($financialYear)
                    : $floor - 1,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('reference_sequences')) {
            return;
        }

        $financialYear = StatutoryFinancialYear::containing(now());

        DB::table('reference_sequences')->where(
            'name',
            ReferenceSequence::purchaseOrderSequenceName($financialYear),
        )->delete();
    }

    private function initialPurchaseOrderCounterValue(StatutoryFinancialYear $financialYear): int
    {
        $floor = (int) ($financialYear->code().'1');
        $max = $floor - 1;

        if (! Schema::hasTable('purchase_orders')) {
            return $max;
        }

        PurchaseOrder::query()
            ->pluck('po_number')
            ->each(function (string $reference) use (&$max, $financialYear): void {
                $parsed = OperationalReferenceParser::parsePurchaseOrderOperationalValue($reference, $financialYear);
                if ($parsed !== null) {
                    $max = max($max, $parsed);
                }
            });

        return $max;
    }
};
