<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\EInvoiceRecordStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\AuditLog;
use App\Models\InventorySale;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceRemediationRecord;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Owner-authorized single-invoice B2C→B2B buyer snapshot correction for INV-0767698 only.
 * Uses controlled DB updates; does not weaken global StatutoryInvoice Eloquent immutability.
 */
final class Inv0767698B2bBuyerRemediation
{
    public const PROMPT_ID = 'P-31-108';

    public const REMEDIATION_KEY = 'RadiumDesk-P-31-108:16507';

    public const TARGET_INVOICE_ID = 16507;

    public const EXPECTED_INVOICE_NUMBER = 'INV-0767698';

    public const EXPECTED_INVENTORY_SALE_ID = 127;

    public const EXPECTED_SOURCE_ORDER_ID = 'POS-6827';

    public const BUYER_GSTIN = '07AEEPJ7680K1Z3';

    public const BUYER_LEGAL_NAME = 'ABHAY JAIN';

    public const BUYER_TRADE_NAME = 'ATTUNE OFFITECH';

    public const EVIDENCE_REFERENCE = 'Owner-supplied GST portal searchtp screenshot (Active, Regular, 2026-10-31); ref P-31-108';

    /**
     * Approved pre-remediation buyer display name on invoice 16507.
     */
    public const EXPECTED_PRE_BUYER_NAME = 'ATTUNE OFFITECH';

    /**
     * @return array<string, mixed>
     */
    public function plan(int $invoiceId, string $invoiceNumber): array
    {
        $this->assertTargetInvoiceOnly($invoiceId, $invoiceNumber);

        $existing = $this->existingRecord();
        if ($existing !== null) {
            return [
                'dry_run' => true,
                'idempotent' => true,
                'already_applied' => true,
                'remediation_key' => self::REMEDIATION_KEY,
                'applied_at' => $existing->applied_at?->toIso8601String(),
                'before' => $existing->before_snapshot,
                'after' => $existing->after_snapshot,
            ];
        }

        $invoice = $this->loadAndValidateInvoice($invoiceId, $invoiceNumber);
        $sale = $this->loadAndValidateSale($invoice);
        $before = $this->snapshot($invoice, $sale);
        $after = $this->proposedAfter($before);
        $simulated = $this->simulateInvoice($invoice, $after);
        $payload = app(EInvoiceIrnPayloadMapper::class)->map($simulated);

        return [
            'dry_run' => true,
            'idempotent' => false,
            'already_applied' => false,
            'remediation_key' => self::REMEDIATION_KEY,
            'invoice_id' => $invoice->id,
            'invoice_number' => (string) $invoice->invoice_number,
            'before' => $before,
            'after' => $after,
            'financial_identity_unchanged' => $this->financialIdentityUnchanged($before, $after),
            'einvoice_payload' => [
                'gaps' => $payload->gaps,
                'is_submittable' => $payload->isSubmittable(),
                'buyer_gstin' => $payload->buyer['gstin'] ?? null,
                'buyer_legal_name' => $payload->buyer['legal_name'] ?? null,
                'buyer_state_code' => $payload->buyer['state_code'] ?? null,
                'buyer_pos_code' => $payload->buyer['pos_code'] ?? null,
                'buyer_trade_name' => self::BUYER_TRADE_NAME,
                'supply_type' => $payload->supplyType,
                'values' => $payload->values,
            ],
            'downstream_notes' => [
                'pdf_regeneration' => 'Not performed by this remediation; run regeneratePresentation after deploy.',
                'einvoice_queue' => 'Not performed by this remediation; run reevaluateEinvoice / backfill after deploy.',
                'irp_submission' => 'Not performed by this remediation.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(int $invoiceId, string $invoiceNumber, string $actor): array
    {
        $this->assertTargetInvoiceOnly($invoiceId, $invoiceNumber);

        $existing = $this->existingRecord();
        if ($existing !== null) {
            return [
                'dry_run' => false,
                'applied' => false,
                'idempotent' => true,
                'already_applied' => true,
                'remediation_key' => self::REMEDIATION_KEY,
                'applied_at' => $existing->applied_at?->toIso8601String(),
            ];
        }

        $invoice = $this->loadAndValidateInvoice($invoiceId, $invoiceNumber);
        $sale = $this->loadAndValidateSale($invoice);
        $before = $this->snapshot($invoice, $sale);
        $after = $this->proposedAfter($before);

        if (! $this->financialIdentityUnchanged($before, $after)) {
            throw new RuntimeException('Proposed remediation would change financial identity.');
        }

        return DB::transaction(function () use ($invoice, $sale, $before, $after, $actor): array {
            if ($this->existingRecord() !== null) {
                return [
                    'dry_run' => false,
                    'applied' => false,
                    'idempotent' => true,
                    'already_applied' => true,
                    'remediation_key' => self::REMEDIATION_KEY,
                ];
            }

            DB::table('statutory_invoices')
                ->where('id', $invoice->id)
                ->update([
                    'buyer_gstin' => self::BUYER_GSTIN,
                    'buyer_name' => self::BUYER_LEGAL_NAME,
                    'updated_at' => now(),
                ]);

            DB::table('inventory_sales')
                ->where('id', $sale->id)
                ->update([
                    'buyer_gstin' => self::BUYER_GSTIN,
                    'buyer_name' => self::BUYER_LEGAL_NAME,
                    'updated_at' => now(),
                ]);

            $appliedAt = now();

            StatutoryInvoiceRemediationRecord::query()->create([
                'remediation_key' => self::REMEDIATION_KEY,
                'statutory_invoice_id' => $invoice->id,
                'invoice_number' => (string) $invoice->invoice_number,
                'prompt_id' => self::PROMPT_ID,
                'actor' => $actor,
                'evidence_reference' => self::EVIDENCE_REFERENCE,
                'before_snapshot' => $before,
                'after_snapshot' => $after,
                'applied_at' => $appliedAt,
            ]);

            AuditLog::query()->create([
                'user_id' => null,
                'event' => 'statutory_invoice.b2b_buyer_remediation',
                'auditable_type' => StatutoryInvoice::class,
                'auditable_id' => $invoice->id,
                'old_values' => $before,
                'new_values' => $after,
                'ip_address' => null,
                'user_agent' => 'artisan:'.self::PROMPT_ID,
            ]);

            return [
                'dry_run' => false,
                'applied' => true,
                'idempotent' => false,
                'already_applied' => false,
                'remediation_key' => self::REMEDIATION_KEY,
                'applied_at' => $appliedAt->toIso8601String(),
                'before' => $before,
                'after' => $after,
            ];
        });
    }

    private function assertTargetInvoiceOnly(int $invoiceId, string $invoiceNumber): void
    {
        if ($invoiceId !== self::TARGET_INVOICE_ID) {
            throw new RuntimeException(
                'Refusing remediation: only statutory invoice id '.self::TARGET_INVOICE_ID.' is permitted.',
            );
        }

        if (trim($invoiceNumber) !== self::EXPECTED_INVOICE_NUMBER) {
            throw new RuntimeException(
                'Refusing remediation: expected invoice number '.self::EXPECTED_INVOICE_NUMBER.'.',
            );
        }
    }

    private function existingRecord(): ?StatutoryInvoiceRemediationRecord
    {
        return StatutoryInvoiceRemediationRecord::query()
            ->where('remediation_key', self::REMEDIATION_KEY)
            ->first();
    }

    private function loadAndValidateInvoice(int $invoiceId, string $invoiceNumber): StatutoryInvoice
    {
        $invoice = StatutoryInvoice::query()
            ->with(['items', 'eInvoiceRecord'])
            ->find($invoiceId);

        if ($invoice === null) {
            throw new RuntimeException('Statutory invoice not found: '.$invoiceId);
        }

        if (trim((string) $invoice->invoice_number) !== $invoiceNumber) {
            throw new RuntimeException('Invoice number mismatch for id '.$invoiceId.'.');
        }

        if ($invoice->status !== StatutoryInvoiceStatus::Issued) {
            throw new RuntimeException('Invoice status must be issued.');
        }

        if ($invoice->channel !== StatutoryInvoiceChannel::DeskPos) {
            throw new RuntimeException('Invoice channel must be desk_pos.');
        }

        if ((int) $invoice->inventory_sale_id !== self::EXPECTED_INVENTORY_SALE_ID) {
            throw new RuntimeException('Unexpected inventory_sale_id.');
        }

        if (trim((string) $invoice->source_order_id) !== self::EXPECTED_SOURCE_ORDER_ID) {
            throw new RuntimeException('Unexpected source_order_id.');
        }

        $gstin = BuyerGstin::normalize($invoice->buyer_gstin);
        if ($gstin !== null) {
            if ($gstin === self::BUYER_GSTIN && $this->existingRecord() === null) {
                throw new RuntimeException('Buyer GSTIN already set without remediation record (inconsistent state).');
            }
            if ($gstin !== self::BUYER_GSTIN) {
                throw new RuntimeException('Unexpected existing buyer GSTIN.');
            }
        }

        if ($gstin === null && trim((string) $invoice->buyer_name) !== self::EXPECTED_PRE_BUYER_NAME) {
            throw new RuntimeException('Unexpected pre-remediation buyer name.');
        }

        $record = $invoice->eInvoiceRecord;
        if (EInvoiceIrnGuard::recordHasIssuedIrn($record)) {
            throw new RuntimeException('Invoice already has an IRN.');
        }

        if (EInvoiceIrnGuard::mustNotResubmit($record)) {
            throw new RuntimeException('E-invoice record is not eligible for buyer remediation.');
        }

        if ($record !== null && $record->status === EInvoiceRecordStatus::Submitted->value) {
            throw new RuntimeException('E-invoice already submitted.');
        }

        $this->assertFinancialSnapshot($invoice);

        return $invoice;
    }

    private function loadAndValidateSale(StatutoryInvoice $invoice): InventorySale
    {
        $sale = InventorySale::query()->find(self::EXPECTED_INVENTORY_SALE_ID);
        if ($sale === null) {
            throw new RuntimeException('Inventory sale '.self::EXPECTED_INVENTORY_SALE_ID.' not found.');
        }

        if ((int) $sale->statutory_invoice_id !== (int) $invoice->id) {
            throw new RuntimeException('Sale statutory_invoice_id mismatch.');
        }

        return $sale;
    }

    private function assertFinancialSnapshot(StatutoryInvoice $invoice): void
    {
        $expected = [
            'taxable_value' => '99800.00',
            'tax_total' => '17964.00',
            'cgst' => '8982.00',
            'sgst' => '8982.00',
            'igst' => '0.00',
            'invoice_value' => '117764.00',
            'place_of_supply_state' => 'Delhi',
            'place_of_supply_state_code' => '07',
        ];

        foreach ($expected as $field => $value) {
            $raw = $invoice->{$field};
            if (in_array($field, ['taxable_value', 'tax_total', 'cgst', 'sgst', 'igst', 'invoice_value'], true)) {
                $actual = number_format((float) $raw, 2, '.', '');
            } else {
                $actual = (string) $raw;
            }
            if ($actual !== $value) {
                throw new RuntimeException('Financial snapshot mismatch on '.$field.': '.$actual);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(StatutoryInvoice $invoice, InventorySale $sale): array
    {
        return [
            'statutory_invoice_id' => (int) $invoice->id,
            'invoice_number' => (string) $invoice->invoice_number,
            'buyer_gstin' => BuyerGstin::normalize($invoice->buyer_gstin),
            'buyer_legal_name' => trim((string) $invoice->buyer_name),
            'buyer_trade_name' => self::BUYER_TRADE_NAME,
            'inventory_sale_id' => (int) $sale->id,
            'source_order_id' => (string) $invoice->source_order_id,
            'taxable_value' => (string) $invoice->taxable_value,
            'tax_total' => (string) $invoice->tax_total,
            'cgst' => (string) $invoice->cgst,
            'sgst' => (string) $invoice->sgst,
            'igst' => (string) $invoice->igst,
            'invoice_value' => (string) $invoice->invoice_value,
            'place_of_supply_state' => (string) $invoice->place_of_supply_state,
            'place_of_supply_state_code' => (string) $invoice->place_of_supply_state_code,
            'evidence_reference' => self::EVIDENCE_REFERENCE,
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @return array<string, mixed>
     */
    private function proposedAfter(array $before): array
    {
        $after = $before;
        $after['buyer_gstin'] = self::BUYER_GSTIN;
        $after['buyer_legal_name'] = self::BUYER_LEGAL_NAME;
        $after['buyer_trade_name'] = self::BUYER_TRADE_NAME;
        $after['remediation_prompt_id'] = self::PROMPT_ID;

        return $after;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function financialIdentityUnchanged(array $before, array $after): bool
    {
        foreach (['taxable_value', 'tax_total', 'cgst', 'sgst', 'igst', 'invoice_value', 'invoice_number'] as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function simulateInvoice(StatutoryInvoice $invoice, array $after): StatutoryInvoice
    {
        $clone = $invoice->replicate();
        $clone->buyer_gstin = $after['buyer_gstin'];
        $clone->buyer_name = $after['buyer_legal_name'];
        $clone->setRelation('items', $invoice->items);

        return $clone;
    }
}
