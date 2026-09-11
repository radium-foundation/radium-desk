<?php

namespace App\Console\Commands;

use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\EInvoiceDateRangeBackfillService;
use App\Services\StatutoryInvoice\StatutoryInvoiceExceptionRemediation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('desk:statutory-remediate-exceptions {--invoice= : One statutory invoice id} {--limit=4 : Max invoices per run} {--irn : Run recover/generate after remediation} {--pdf : Regenerate PDF after IRN}')]
#[Description('Controlled remediation for verified Gate 1 B2B IRN exceptions.')]
class StatutoryExceptionRemediateCommand extends Command
{
    public function handle(
        StatutoryInvoiceExceptionRemediation $remediation,
        EInvoiceDateRangeBackfillService $backfill,
    ): int {
        $limit = max(1, (int) $this->option('limit'));
        $invoiceOption = $this->option('invoice');
        $singleId = is_numeric($invoiceOption) ? (int) $invoiceOption : null;
        $ids = $singleId !== null
            ? [$singleId]
            : StatutoryInvoiceExceptionRemediation::EXCEPTION_INVOICE_IDS;

        $results = [];
        $processed = 0;
        foreach ($ids as $id) {
            if ($processed >= $limit) {
                break;
            }

            $invoice = StatutoryInvoice::query()->with('items')->find($id);
            if ($invoice === null) {
                $results[] = ['invoice_id' => $id, 'applied' => false, 'reason' => 'not_found'];

                continue;
            }

            $results[] = $remediation->remediate($invoice);
            $processed++;

            if ((bool) $this->option('irn')) {
                $results[count($results) - 1]['irn'] = $backfill->processOne(
                    $invoice->fresh(['items', 'eInvoiceRecord']) ?? $invoice,
                    recover: true,
                    generate: true,
                    pdf: (bool) $this->option('pdf'),
                );
            }
        }

        $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
