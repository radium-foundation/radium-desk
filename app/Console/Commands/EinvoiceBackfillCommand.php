<?php

namespace App\Console\Commands;

use App\Services\StatutoryInvoice\EInvoiceDateRangeBackfillService;
use App\Services\StatutoryInvoice\EInvoiceReconciliationService;
use App\Support\AppDateFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('desk:einvoice-backfill {--from=2026-09-05 00:00:00 : Inclusive issued_at start in app timezone} {--to= : Inclusive issued_at end; default now} {--inventory : Read-only classification; never GENERATE} {--recover : Get-IRN recovery only} {--generate : GENERATE after recovery confirms absence} {--pdf : Presentation-only PDF rewrite} {--limit=10 : Max invoices to process} {--invoice= : Process one statutory invoice id} {--write= : Optional JSON output path}')]
#[Description('Bounded IRN reconciliation/backfill. Inventory is read-only. GENERATE is oldest-first and recover-first.')]
class EinvoiceBackfillCommand extends Command
{
    public function handle(
        EInvoiceReconciliationService $reconciliation,
        EInvoiceDateRangeBackfillService $backfill,
    ): int {
        $from = CarbonImmutable::parse((string) $this->option('from'), AppDateFormatter::timezone());
        $toRaw = $this->option('to');
        $to = is_string($toRaw) && trim($toRaw) !== ''
            ? CarbonImmutable::parse($toRaw, AppDateFormatter::timezone())
            : CarbonImmutable::now(AppDateFormatter::timezone());

        if ($from->lt(CarbonImmutable::parse(EInvoiceReconciliationService::RANGE_START, AppDateFormatter::timezone()))) {
            $this->error('The backfill start cannot be before 2026-09-05 00:00:00.');

            return self::FAILURE;
        }

        $inventoryOnly = (bool) $this->option('inventory');
        $recover = (bool) $this->option('recover') || (bool) $this->option('generate');
        $generate = (bool) $this->option('generate');
        $pdf = (bool) $this->option('pdf');
        $limit = max(1, (int) $this->option('limit'));
        $invoiceOption = $this->option('invoice');
        $invoiceId = is_numeric($invoiceOption) ? (int) $invoiceOption : null;

        if ($inventoryOnly && ($generate || $recover || $pdf)) {
            $this->error('--inventory cannot be combined with --recover, --generate, or --pdf.');

            return self::FAILURE;
        }

        if ($inventoryOnly || (! $recover && ! $generate && ! $pdf)) {
            $report = $reconciliation->inventory($from, $to);
            $this->writeOutput($report);

            return self::SUCCESS;
        }

        $results = $backfill->processRange(
            from: $from,
            to: $to,
            recover: $recover,
            generate: $generate,
            pdf: $pdf,
            limit: $limit,
            invoiceId: $invoiceId,
        );
        $this->writeOutput([
            'from' => $from->toDateTimeString(),
            'to' => $to->toDateTimeString(),
            'recover' => $recover,
            'generate' => $generate,
            'pdf' => $pdf,
            'limit' => $limit,
            'results' => $results,
        ]);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeOutput(array $payload): void
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->line((string) $json);

        $path = $this->option('write');
        if (is_string($path) && trim($path) !== '') {
            file_put_contents($path, $json);
            $this->info('Wrote '.$path);
        }
    }
}
