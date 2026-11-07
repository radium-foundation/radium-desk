<?php

namespace App\Services\ReturnExchange;

use App\Enums\ReturnExchangeCaseSourceKind;
use App\Enums\ReturnExchangeReplacementCategory;
use App\Models\ReturnExchangeCase;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ReturnExchangeHybridIntakeService
{
    public function __construct(
        private readonly ReturnExchangeCaseIntakeService $intake,
        private readonly ReturnExchangeCaseDuplicateOpenGuard $duplicateGuard,
        private readonly ReturnExchangeHybridApprovalService $approvals,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(User $actor, array $payload): ReturnExchangeCase
    {
        $category = ReturnExchangeReplacementCategory::from((string) $payload['replacement_category']);
        $saleId = isset($payload['inventory_sale_id']) ? (int) $payload['inventory_sale_id'] : 0;
        $returnSerial = (string) ($payload['lines'][0]['serial_number'] ?? '');

        if ($saleId > 0 && $returnSerial !== '') {
            $this->duplicateGuard->assertNoDuplicateOpenCase($saleId, $returnSerial);
        }

        return DB::transaction(function () use ($actor, $payload, $category): ReturnExchangeCase {
            $payload['source_kind'] = ReturnExchangeCaseSourceKind::PosSale->value;

            $case = $this->intake->create($actor, $payload);

            $case->update([
                'replacement_category' => $category,
                'customer_request_summary' => $payload['customer_request_summary'] ?? null,
                'requested_connector_change' => $payload['requested_connector_change'] ?? null,
                'hybrid_workflow_notes' => $payload['hybrid_workflow_notes'] ?? null,
            ]);

            $this->approvals->ensurePendingRows($case->fresh());

            return $case->fresh([
                'lines',
                'requester',
                'branch',
                'hybridApprovals',
                'inventorySale.statutoryInvoice',
            ]);
        });
    }
}
