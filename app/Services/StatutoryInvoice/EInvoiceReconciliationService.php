<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\EInvoiceRecordStatus;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\StatutoryInvoice;
use App\Support\AppDateFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Read-only B2B IRN inventory. Does not GENERATE, recover, or rewrite PDFs.
 */
final class EInvoiceReconciliationService
{
    public const RANGE_START = '2026-09-05 00:00:00';

    public function __construct(
        private readonly EInvoiceEligibility $eligibility,
        private readonly EInvoiceIrnPayloadMapper $mapper,
    ) {}

    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     counts: array<string, int>,
     *     invoices: list<array<string, mixed>>
     * }
     */
    public function inventory(?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $from = $from ?? CarbonImmutable::parse(self::RANGE_START, AppDateFormatter::timezone());
        $to = $to ?? CarbonImmutable::now(AppDateFormatter::timezone());

        $invoices = $this->invoicesInRange($from, $to);
        $rows = [];
        $counts = [
            'examined' => 0,
            'b2b_irn_present' => 0,
            'b2b_submitted_or_processing' => 0,
            'b2b_eligible_never_submitted' => 0,
            'b2b_missing_statutory' => 0,
            'b2b_permanently_ineligible' => 0,
            'b2c_not_eligible' => 0,
            'cancelled' => 0,
            'historical_excluded' => 0,
            'other' => 0,
            'b2b' => 0,
            'b2c' => 0,
        ];

        foreach ($invoices as $invoice) {
            $row = $this->classify($invoice);
            $rows[] = $row;
            $counts['examined']++;
            $bucket = $row['bucket'];
            if (! array_key_exists($bucket, $counts)) {
                $counts['other']++;
            } else {
                $counts[$bucket]++;
            }
            if ($row['is_b2b']) {
                $counts['b2b']++;
            }
            if ($row['is_b2c']) {
                $counts['b2c']++;
            }
        }

        return [
            'from' => $from->toDateTimeString(),
            'to' => $to->toDateTimeString(),
            'counts' => $counts,
            'invoices' => $rows,
        ];
    }

    /**
     * @return Collection<int, StatutoryInvoice>
     */
    public function invoicesInRange(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return StatutoryInvoice::query()
            ->with(['items', 'eInvoiceRecord', 'document'])
            ->where('issued_at', '>=', $from->toDateTimeString())
            ->where('issued_at', '<=', $to->toDateTimeString())
            ->orderBy('issued_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function classify(StatutoryInvoice $invoice): array
    {
        $invoice->loadMissing(['items', 'eInvoiceRecord']);
        $record = $invoice->eInvoiceRecord;
        $eligibility = $this->eligibility->evaluate($invoice);
        $payload = $this->mapper->map($invoice);
        $hasIrn = EInvoiceIrnGuard::recordHasIssuedIrn($record);
        $status = $record?->status;
        $skip = is_string($record?->response_payload['skip_reason'] ?? null)
            ? (string) $record->response_payload['skip_reason']
            : null;

        $bucket = 'other';
        $reason = $eligibility->reason;
        $isB2c = $eligibility->reason === 'b2c_not_eligible' || $skip === 'b2c_not_eligible';
        $isB2b = ! $isB2c && $invoice->buyer_gstin !== null && trim((string) $invoice->buyer_gstin) !== '';

        if ($invoice->status === StatutoryInvoiceStatus::Cancelled) {
            $bucket = 'cancelled';
        } elseif ($eligibility->reason === 'outside_invoice_scope') {
            $bucket = 'historical_excluded';
        } elseif ($isB2c) {
            $bucket = 'b2c_not_eligible';
        } elseif ($hasIrn) {
            $bucket = 'b2b_irn_present';
        } elseif (in_array($status, [
            EInvoiceRecordStatus::Processing->value,
            EInvoiceRecordStatus::Ambiguous->value,
            EInvoiceRecordStatus::Submitted->value,
        ], true)) {
            $bucket = 'b2b_submitted_or_processing';
        } elseif (! $payload->isSubmittable() || $eligibility->reason === 'incomplete_gst' || $skip === 'irp_fields_incomplete') {
            $bucket = 'b2b_missing_statutory';
            $reason = $payload->gaps !== [] ? implode(',', $payload->gaps) : $reason;
        } elseif (! $eligibility->eligible) {
            $bucket = 'b2b_permanently_ineligible';
        } elseif ($eligibility->eligible && ! $hasIrn) {
            $bucket = 'b2b_eligible_never_submitted';
        }

        return [
            'invoice_id' => $invoice->id,
            'invoice_number' => (string) $invoice->invoice_number,
            'bucket' => $bucket,
            'is_b2b' => $isB2b,
            'is_b2c' => $isB2c,
            'channel' => $invoice->channel instanceof \BackedEnum ? $invoice->channel->value : (string) $invoice->channel,
            'source_id' => (string) ($invoice->source_id ?? ''),
            'source_order_id' => (string) ($invoice->source_order_id ?? ''),
            'issued_at' => optional($invoice->issued_at)?->timezone(AppDateFormatter::timezone())->toDateTimeString(),
            'buyer_gstin' => $invoice->buyer_gstin,
            'seller_gstin' => $invoice->seller_gstin,
            'taxable_value' => (string) $invoice->taxable_value,
            'tax_total' => (string) $invoice->tax_total,
            'invoice_value' => (string) $invoice->invoice_value,
            'e_invoice_status' => $status,
            'irn' => $hasIrn ? trim((string) $record?->irn) : null,
            'ack_no' => $hasIrn ? $this->nullable((string) ($record?->ack_no ?? '')) : null,
            'signed_invoice' => $record?->hasPersistedSignedInvoice() ? 'present' : 'absent',
            'signed_qr' => $hasIrn && is_string($record?->signed_qr) && trim($record->signed_qr) !== '' ? 'present' : 'absent',
            'eligible' => $eligibility->eligible,
            'eligibility_reason' => $eligibility->reason,
            'mapper_gaps' => $payload->gaps,
            'reason_if_no_irn' => $hasIrn ? null : ($reason ?: $skip),
        ];
    }

    private function nullable(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
