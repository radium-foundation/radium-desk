<?php

namespace App\Console\Commands;

use App\Services\StatutoryInvoice\EInvoiceRequestPayloadSyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('desk:einvoice-sync-request-payload {invoice_id : Statutory invoice primary key (single invoice only)}')]
#[Description('Recompute and persist one e-invoice request_payload from stored invoice data. Never submits to IRP or mutates outbox/queue.')]
class EinvoiceSyncRequestPayloadCommand extends Command
{
    public function handle(EInvoiceRequestPayloadSyncService $sync): int
    {
        $raw = $this->argument('invoice_id');
        if (! is_numeric($raw) || (string) (int) $raw !== trim((string) $raw)) {
            $this->error('invoice_id must be a single positive integer.');

            return self::FAILURE;
        }

        $invoiceId = (int) $raw;
        if ($invoiceId <= 0) {
            $this->error('invoice_id must be a positive integer.');

            return self::FAILURE;
        }

        $report = $sync->sync($invoiceId);
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return ($report['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }
}
