<?php

use App\Models\InventorySale;
use App\Models\RefundRequest;
use App\Models\ReferenceSequence;
use App\Models\ServiceOrder;
use App\Support\OperationalReference\OperationalReferenceParser;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $rows = [
            [
                'name' => ReferenceSequence::REFUND_OPERATIONAL,
                'current_value' => $this->initialRefundCounterValue(),
            ],
            [
                'name' => ReferenceSequence::SERVICE_ORDER_OPERATIONAL,
                'current_value' => $this->initialServiceOrderCounterValue(),
            ],
            [
                'name' => ReferenceSequence::PRODUCT_POS_OPERATIONAL,
                'current_value' => $this->initialProductPosCounterValue(),
            ],
        ];

        foreach ($rows as $row) {
            DB::table('reference_sequences')->updateOrInsert(
                ['name' => $row['name']],
                [
                    'current_value' => $row['current_value'],
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('reference_sequences')->whereIn('name', [
            ReferenceSequence::REFUND_OPERATIONAL,
            ReferenceSequence::SERVICE_ORDER_OPERATIONAL,
            ReferenceSequence::PRODUCT_POS_OPERATIONAL,
        ])->delete();
    }

    private function initialRefundCounterValue(): int
    {
        $max = OperationalReferenceParser::REFUND_FLOOR - 1;

        RefundRequest::withTrashed()
            ->where('reference_no', 'like', 'REF-%')
            ->pluck('reference_no')
            ->each(function (string $reference) use (&$max): void {
                $parsed = OperationalReferenceParser::parseRefundOperationalValue($reference);
                if ($parsed !== null) {
                    $max = max($max, $parsed);
                }
            });

        return $max;
    }

    private function initialServiceOrderCounterValue(): int
    {
        $max = OperationalReferenceParser::SERVICE_ORDER_FLOOR - 1;

        ServiceOrder::query()
            ->pluck('order_number')
            ->each(function (string $reference) use (&$max): void {
                $parsed = OperationalReferenceParser::parseServiceOrderOperationalValue($reference);
                if ($parsed !== null) {
                    $max = max($max, $parsed);
                }
            });

        return $max;
    }

    private function initialProductPosCounterValue(): int
    {
        $max = OperationalReferenceParser::PRODUCT_POS_FLOOR - 1;

        InventorySale::query()
            ->pluck('sale_no')
            ->each(function (string $reference) use (&$max): void {
                $parsed = OperationalReferenceParser::parseProductPosOperationalValue($reference);
                if ($parsed !== null) {
                    $max = max($max, $parsed);
                }
            });

        return $max;
    }
};
