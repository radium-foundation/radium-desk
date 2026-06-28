<?php

namespace App\Console\Commands;

use App\Infrastructure\IntegrationHealth\IntegrationHealthService;
use App\Infrastructure\Reconciliation\OrderReconciliationService;
use App\Infrastructure\Reconciliation\ReconciliationCsvExporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('radiumbox:reconcile
    {--csv= : Export reconciliation rows to the given CSV file path}')]
#[Description('Analyse all orders and produce a data reconciliation report')]
class ReconcileRadiumBoxOrdersCommand extends Command
{
    public function __construct(
        private readonly OrderReconciliationService $reconciliationService,
        private readonly ReconciliationCsvExporter $csvExporter,
        private readonly IntegrationHealthService $integrationHealthService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $report = $this->reconciliationService->report();

        $this->info('Order reconciliation report');
        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Orders', $report->totalOrders],
                ['Orders Missing Serial', $report->ordersMissingSerial],
                ['Orders Missing Device Model', $report->ordersMissingDeviceModel],
                ['Orders Missing Both', $report->ordersMissingBoth],
                ['Orders Awaiting Sync', $report->ordersAwaitingSync],
                ['Orders with Failed Sync', $report->ordersWithFailedSync],
                ['Orders Successfully Synced', $report->ordersSuccessfullySynced],
                ['Orders using Manual Serial', $report->ordersUsingManualSerial],
                ['Orders using Manual Device Model', $report->ordersUsingManualDeviceModel],
            ],
        );

        $this->newLine();
        $this->info('Integration health snapshot');
        $this->line(json_encode($this->integrationHealthService->all(), JSON_PRETTY_PRINT));

        $csvPath = $this->option('csv');
        $csvPath = is_string($csvPath) ? trim($csvPath) : '';

        if ($csvPath !== '') {
            $rows = $this->reconciliationService->orderRows();
            $csv = $this->csvExporter->export($rows);

            if (file_put_contents($csvPath, $csv) === false) {
                $this->error("Unable to write CSV export to: {$csvPath}");

                return self::FAILURE;
            }

            $this->newLine();
            $this->info(sprintf('CSV export written to %s (%d rows).', $csvPath, count($rows)));
        }

        Log::info('RadiumBox order reconciliation completed.', $report->toArray());

        return self::SUCCESS;
    }
}
