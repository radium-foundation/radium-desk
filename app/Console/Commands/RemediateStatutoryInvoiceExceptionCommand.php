<?php

namespace App\Console\Commands;

use App\Models\OutboxEvent;
use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\EInvoiceIrpSubmissionHold;
use App\Services\StatutoryInvoice\EInvoiceOutboxWriter;
use App\Services\StatutoryInvoice\EInvoiceProcessor;
use App\Services\StatutoryInvoice\StatutoryInvoiceExceptionRemediation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('desk:remediate-statutory-invoice-exception {invoice_id : Statutory invoice id} {--apply : Apply the remediation after dry-run validation} {--actor= : Operator or prompt id for audit logging} {--verify-irp-hold : Prove the configured IRP hold blocks processor submission}')]
#[Description('Dry-run or apply a whitelisted statutory invoice snapshot remediation. Does not submit to IRP.')]
class RemediateStatutoryInvoiceExceptionCommand extends Command
{
    public function handle(
        StatutoryInvoiceExceptionRemediation $remediation,
        EInvoiceProcessor $processor,
    ): int {
        $invoiceId = (int) $this->argument('invoice_id');
        $invoice = StatutoryInvoice::query()->with('items')->find($invoiceId);
        if ($invoice === null) {
            $this->error('Statutory invoice not found: '.$invoiceId);

            return self::FAILURE;
        }

        if ($this->option('verify-irp-hold')) {
            return $this->verifyIrpHold($invoice, $processor);
        }

        $apply = (bool) $this->option('apply');
        $actor = trim((string) ($this->option('actor') ?? ''));
        $actor = $actor !== '' ? $actor : 'artisan';

        if ($apply && ! EInvoiceIrpSubmissionHold::isHeld($invoiceId)) {
            $this->error('Refusing --apply while invoice '.$invoiceId.' is not in STATUTORY_EINVOICE_IRP_HELD_INVOICE_IDS.');

            return self::FAILURE;
        }

        $report = $apply
            ? $remediation->remediate($invoice, apply: true, actor: $actor)
            : $remediation->preview($invoice);

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if (($report['applied'] ?? false) !== true && $apply) {
            return self::FAILURE;
        }

        if (! $apply) {
            $this->info('Dry-run only. Pass --apply after IRP hold is verified.');
        }

        return self::SUCCESS;
    }

    private function verifyIrpHold(StatutoryInvoice $invoice, EInvoiceProcessor $processor): int
    {
        if (! EInvoiceIrpSubmissionHold::isHeld((int) $invoice->id)) {
            $this->error('Invoice '.$invoice->id.' is not held via STATUTORY_EINVOICE_IRP_HELD_INVOICE_IDS.');

            return self::FAILURE;
        }

        $outbox = OutboxEvent::query()
            ->where('idempotency_key', EInvoiceOutboxWriter::idempotencyKeyForInvoice($invoice))
            ->first();

        if ($outbox !== null) {
            $processor->process($outbox);
            $this->line(json_encode(['outbox_processed' => true], JSON_PRETTY_PRINT));
        }

        $generate = $processor->generateAfterConfirmedAbsent($invoice->fresh(['items']) ?? $invoice);
        $payload = [
            'held' => true,
            'hold_reason' => EInvoiceIrpSubmissionHold::holdReason(),
            'generate_outcome' => $generate->outcome->value,
            'generate_payload' => $generate->payload,
            'irn' => $invoice->fresh(['eInvoiceRecord'])?->eInvoiceRecord?->irn,
        ];
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $reason = is_array($generate->payload) ? ($generate->payload['reason'] ?? null) : null;
        if ($reason !== EInvoiceIrpSubmissionHold::holdReason()) {
            $this->error('IRP hold verification failed: processor did not return irp_submission_held.');

            return self::FAILURE;
        }

        $this->info('IRP hold verified for invoice '.$invoice->id.'.');

        return self::SUCCESS;
    }
}
