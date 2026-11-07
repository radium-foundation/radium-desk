<?php

namespace App\Services\ReturnExchange;

use App\Enums\ReturnExchangeCaseStatus;
use App\Enums\ReturnExchangeHybridApprovalDecision;
use App\Enums\ReturnExchangeHybridApprovalType;
use App\Enums\ReturnExchangeReplacementCategory;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\ReturnExchangeCase;
use App\Services\ReturnExchange\Data\ReturnExchangeWorkflowChecklistItem;

final class ReturnExchangeCaseWorkflowReadinessService
{
    public function __construct(
        private readonly ReturnExchangeCaseRecordedTaxOutcomeVerifier $taxVerifier,
    ) {}

    /**
     * @return list<ReturnExchangeWorkflowChecklistItem>
     */
    public function checklist(ReturnExchangeCase $case): array
    {
        $case->loadMissing([
            'inventorySale.statutoryInvoice',
            'inventorySale.serials.serial',
            'receipts',
            'hybridApprovals',
            'lines',
        ]);

        $items = [];

        $items[] = $this->item(
            'replacement_category',
            'Replacement category recorded',
            $case->replacement_category !== null,
            $case->replacement_category?->label() ?? 'Select a category on case creation.',
        );

        $returnSerial = $case->lines->first()?->serial_number;
        $items[] = $this->item(
            'return_serial',
            'Return serial identified on case',
            $returnSerial !== null && trim((string) $returnSerial) !== '',
            'Case line must specify the return serial.',
        );

        $items[] = $this->item(
            'physical_receipt',
            'Return physically received',
            $case->receipts->isNotEmpty(),
            'Use existing receive workflow on the case detail page.',
        );

        $inspected = $case->receipts->contains(fn ($r) => $r->inspected_at !== null);
        $items[] = $this->item(
            'inspection',
            'Inspection completed',
            $inspected,
            'Inspect returned goods before replacement.',
        );

        $invoice = $case->inventorySale?->statutoryInvoice;
        $issued = $invoice !== null && $invoice->status === StatutoryInvoiceStatus::Issued;
        $items[] = $this->item(
            'statutory_invoice_status',
            'Original invoice status reviewed',
            $invoice !== null,
            $invoice === null
                ? 'Link a POS sale with a statutory invoice.'
                : 'Invoice '.$invoice->invoice_number.' is '.$invoice->status->value.'.',
        );

        if ($issued) {
            $tax = $this->taxVerifier->verify($case);
            $items[] = $this->item(
                'statutory_evidence',
                'Statutory evidence (cancellation or credit note) recorded',
                $tax->verified,
                $tax->blockerMessage ?? 'CA-approved document treatment must be recorded before inventory completion.',
            );
        }

        foreach ($this->requiredApprovalTypes($case) as $type) {
            $approval = $case->hybridApprovals->firstWhere('approval_type', $type);
            $ok = $approval !== null && $approval->decision === ReturnExchangeHybridApprovalDecision::Approved;
            $items[] = $this->item(
                'approval_'.$type->value,
                $type->label().' approval',
                $ok,
                $ok ? null : 'Pending '.$type->label().' approval.',
            );
        }

        $planned = trim((string) ($case->planned_replacement_serial_number ?? ''));
        $items[] = $this->item(
            'planned_replacement',
            'Replacement serial selected (intent only)',
            $planned !== '',
            'Record the planned replacement serial in the management panel. This does not allocate stock.',
        );

        if ($case->replacement_category?->mayRecordPriceDifference()) {
            $items[] = $this->item(
                'price_difference',
                'Price difference verified',
                (bool) $case->price_difference_verified,
                'Customer-requested exchanges require verified price difference approval.',
            );
        }

        $items[] = $this->item(
            'completion_gate',
            'Authorized sale-row exchange (orchestration)',
            false,
            'Deliberately blocked: unified completion orchestration is not enabled in this release. Owner and CA approval required.',
        );

        return $items;
    }

    /**
     * @return list<string>
     */
    public function blockingReasons(ReturnExchangeCase $case): array
    {
        return array_values(array_filter(
            array_map(
                fn (ReturnExchangeWorkflowChecklistItem $item): ?string => $item->passed ? null : ($item->detail ?? $item->label),
                $this->checklist($case),
            ),
        ));
    }

    public function mayAttemptCompletion(ReturnExchangeCase $case): bool
    {
        return false;
    }

    /**
     * @return list<ReturnExchangeHybridApprovalType>
     */
    private function requiredApprovalTypes(ReturnExchangeCase $case): array
    {
        $types = [
            ReturnExchangeHybridApprovalType::BusinessCategory,
            ReturnExchangeHybridApprovalType::Statutory,
        ];

        if ($case->replacement_category === ReturnExchangeReplacementCategory::Goodwill) {
            $types[] = ReturnExchangeHybridApprovalType::Goodwill;
        }

        if ($case->replacement_category?->mayRecordPriceDifference()) {
            $types[] = ReturnExchangeHybridApprovalType::PriceDifference;
        }

        if ($case->status === ReturnExchangeCaseStatus::Inspected
            || $case->replacement_category === ReturnExchangeReplacementCategory::CommercialReturn) {
            $types[] = ReturnExchangeHybridApprovalType::Finance;
        }

        $types[] = ReturnExchangeHybridApprovalType::FinalCompletion;

        return $types;
    }

    private function item(string $key, string $label, bool $passed, ?string $detail): ReturnExchangeWorkflowChecklistItem
    {
        return new ReturnExchangeWorkflowChecklistItem($key, $label, $passed, $detail);
    }
}
