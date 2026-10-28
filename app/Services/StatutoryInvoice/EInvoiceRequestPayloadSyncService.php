<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\EInvoiceRecordStatus;
use App\Models\EInvoiceRecord;
use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\Data\EInvoiceIrnPayload;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Persist a freshly mapped IRN request_payload for one invoice only.
 * Does not call the e-invoice gateway, outbox, queue, PDF finalization, or Get-IRN.
 */
final class EInvoiceRequestPayloadSyncService
{
    public function __construct(
        private readonly EInvoiceIrnPayloadMapper $mapper,
        private readonly EInvoiceEligibility $eligibility,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function sync(int $invoiceId): array
    {
        if ($invoiceId <= 0) {
            return $this->failure('invalid_invoice_id', $invoiceId, 'Invoice id must be a positive integer.');
        }

        try {
            return DB::transaction(function () use ($invoiceId): array {
                $invoice = StatutoryInvoice::query()
                    ->with('items')
                    ->whereKey($invoiceId)
                    ->lockForUpdate()
                    ->first();

                if ($invoice === null) {
                    return $this->failure('invoice_not_found', $invoiceId, 'Statutory invoice not found.');
                }

                $record = EInvoiceRecord::query()
                    ->where('invoice_id', $invoiceId)
                    ->lockForUpdate()
                    ->first();

                if ($record === null) {
                    return $this->failure('einvoice_record_missing', $invoiceId, 'E-invoice record not found for this invoice.');
                }

                if (EInvoiceIrnGuard::recordHasIssuedIrn($record)) {
                    return $this->failure('irn_already_present', $invoiceId, 'Refusing to sync while an IRN is already issued.');
                }

                if (EInvoiceIrnGuard::mustNotResubmit($record)) {
                    return $this->failure('status_blocks_sync', $invoiceId, 'E-invoice status blocks payload sync.');
                }

                if (EInvoiceIrnGuard::mustRecoverInsteadOfGenerate($record)) {
                    return $this->failure('recover_required', $invoiceId, 'Invoice is processing or ambiguous; resolve recovery before payload sync.');
                }

                $beforeStatus = (string) $record->status;
                $beforeGaps = $this->gapList($record->request_payload);

                $payload = $this->mapper->map($invoice);
                $attributes = $this->attributesForPayload($invoice, $payload);
                $attributes = EInvoiceIrnGuard::attributesWithoutClearingIssuedIrn($attributes);

                EInvoiceRecord::query()->updateOrCreate(
                    ['invoice_id' => $invoice->id],
                    $attributes,
                );

                $fresh = EInvoiceRecord::query()
                    ->where('invoice_id', $invoice->id)
                    ->firstOrFail();
                $afterGaps = $this->gapList($fresh->request_payload);

                return [
                    'ok' => true,
                    'invoice_id' => $invoice->id,
                    'invoice_number' => (string) $invoice->invoice_number,
                    'before' => [
                        'e_invoice_status' => $beforeStatus,
                        'gap_codes' => $beforeGaps,
                        'gap_count' => count($beforeGaps),
                    ],
                    'after' => [
                        'e_invoice_status' => (string) $fresh->status,
                        'gap_codes' => $afterGaps,
                        'gap_count' => count($afterGaps),
                        'is_submittable' => $payload->isSubmittable(),
                        'derived_unit' => $payload->items[0]['unit'] ?? null,
                        'derived_is_servc' => $payload->items[0]['is_servc'] ?? null,
                    ],
                    'outcome' => $payload->isSubmittable()
                        ? ($attributes['status'] === EInvoiceRecordStatus::Queued->value ? 'queued_payload_synced' : 'skipped_not_eligible')
                        : 'skipped_irp_fields_incomplete',
                ];
            });
        } catch (Throwable $exception) {
            return $this->failure('sync_failed', $invoiceId, $exception->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesForPayload(StatutoryInvoice $invoice, EInvoiceIrnPayload $payload): array
    {
        $provider = (string) config('statutory_invoices.einvoice.provider', 'none');
        $syncedAt = now()->toIso8601String();

        if (! $payload->isSubmittable()) {
            return [
                'provider' => $provider,
                'status' => EInvoiceRecordStatus::Skipped->value,
                'request_payload' => $payload->toArray(),
                'response_payload' => [
                    'skip_reason' => 'irp_fields_incomplete',
                    'gaps' => $payload->gaps,
                    'payload_synced_at' => $syncedAt,
                ],
            ];
        }

        $decision = $this->eligibility->evaluate($invoice);
        if (! $decision->eligible) {
            return [
                'provider' => $provider,
                'status' => EInvoiceRecordStatus::Skipped->value,
                'request_payload' => $payload->toArray(),
                'response_payload' => [
                    'skip_reason' => $decision->reason,
                    'payload_synced_at' => $syncedAt,
                ],
            ];
        }

        return [
            'provider' => $provider,
            'status' => EInvoiceRecordStatus::Queued->value,
            'request_payload' => $payload->toArray(),
            'response_payload' => [
                'queue_reason' => $decision->reason,
                'payload_synced_at' => $syncedAt,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $requestPayload
     * @return list<string>
     */
    private function gapList(?array $requestPayload): array
    {
        if (! is_array($requestPayload)) {
            return [];
        }

        $gaps = $requestPayload['gaps'] ?? [];

        return is_array($gaps) ? array_values(array_map('strval', $gaps)) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(string $code, int $invoiceId, string $message): array
    {
        return [
            'ok' => false,
            'invoice_id' => $invoiceId,
            'error_code' => $code,
            'message' => $message,
        ];
    }
}
