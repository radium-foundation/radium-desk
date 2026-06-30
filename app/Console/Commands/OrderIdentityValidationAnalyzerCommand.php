<?php

namespace App\Console\Commands;

use App\Data\OrderIdentityValidationAnalysis;
use App\Data\OrderIdentityValidationAnalysisBatchResult;
use App\Services\OrderIdentityValidationAnalyzerService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('orders:analyze-validation
    {--order= : Analyze a specific external order ID}
    {--failed-only : Only include orders with explicit IRA validation failures}
    {--limit= : Maximum number of orders to scan}')]
#[Description('Analyze active orders that still fail IRA validation (read-only diagnostics)')]
class OrderIdentityValidationAnalyzerCommand extends Command
{
    public function __construct(
        private readonly OrderIdentityValidationAnalyzerService $analyzerService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $externalOrderId = $this->option('order');
        $externalOrderId = is_string($externalOrderId) && $externalOrderId !== ''
            ? $externalOrderId
            : null;

        $result = $this->analyzerService->analyze(
            externalOrderId: $externalOrderId,
            failedOnly: (bool) $this->option('failed-only'),
            limit: $this->resolveLimit(),
        );

        if ($result->failureCount === 0) {
            $this->info(sprintf(
                'No validation failures found among %d scanned order(s).',
                $result->ordersScanned,
            ));

            return self::SUCCESS;
        }

        foreach ($result->failures as $failure) {
            $this->displayFailure($failure);
        }

        $this->displayStatistics($result);

        return self::SUCCESS;
    }

    private function displayFailure(OrderIdentityValidationAnalysis $failure): void
    {
        $this->newLine();
        $this->line('--------------------------------------------------');
        $this->newLine();
        $this->line('Order:');
        $this->line((string) $failure->internalId);
        $this->line($failure->externalOrderId);
        $this->newLine();
        $this->line('Current Database Values');
        $this->line('-----------------------');
        $this->line('Product');
        $this->line($failure->productName ?? '—');
        $this->line('Device Model');
        $this->line($failure->deviceModel ?? '—');
        $this->line('Serial');
        $this->line($failure->serialNumber ?? '—');
        $this->newLine();
        $this->line('Validator Selected');
        $this->line('------------------');
        $this->line($failure->validatorClass ?? 'None');
        $this->newLine();
        $this->line('Validator Result');
        $this->line('----------------');
        $this->line($failure->validatorResultLabel());
        $this->newLine();
        $this->line('Failure Reason');
        $this->line('--------------');
        $this->line($failure->failureReason ?? '—');
        $this->newLine();
        $this->line('Rule Failed');
        $this->line('-----------');
        $this->line($failure->ruleFailed ?? '—');
        $this->newLine();
        $this->line('RadiumBox Sync');
        $this->line('--------------');
        $this->line($failure->radiumBoxSyncLabel);
        $this->newLine();
        $this->line('Automation Status');
        $this->line('-----------------');
        $this->line($failure->automationStatusLabel);
        $this->newLine();
        $this->line('Assignment');
        $this->line('----------');
        $this->line($failure->assigneeName ?? 'Unassigned');
        $this->line($failure->assigneeRole ?? '—');
        $this->newLine();
        $this->line('Recommendation');
        $this->line('--------------');
        $this->line($failure->recommendation->displayLabel());
    }

    private function displayStatistics(OrderIdentityValidationAnalysisBatchResult $result): void
    {
        $this->newLine();
        $this->info('Validation analysis summary');
        $this->line('Orders scanned: '.$result->ordersScanned);
        $this->line('Failures found: '.$result->failureCount);
        $this->line('Elapsed time: '.$result->elapsedSeconds.'s');
        $this->newLine();

        $this->info('Validation failures grouped by product');
        foreach ($result->failuresByProduct as $product => $count) {
            $this->line(sprintf('- %s (%d orders)', $product, $count));
        }

        $this->newLine();
        $this->info('Validation failures grouped by validator rule');
        if ($result->failuresByValidatorRule === []) {
            $this->line('None');
        } else {
            foreach ($result->failuresByValidatorRule as $rule => $count) {
                $this->line(sprintf('- %s (%d orders)', $rule, $count));
            }
        }

        $this->newLine();
        $this->info('Validation failures grouped by category');
        foreach ($result->failuresByGroup as $group => $count) {
            $this->line(sprintf('- %s (%d orders)', $group, $count));
        }

        $this->newLine();
        $this->info('Top repeated invalid serial patterns');
        if ($result->topInvalidSerialPatterns === []) {
            $this->line('None');
        } else {
            foreach ($result->topInvalidSerialPatterns as $serial => $count) {
                $this->line(sprintf('%s (%d orders)', $serial, $count));
            }
        }
    }

    private function resolveLimit(): ?int
    {
        $limit = $this->option('limit');

        if ($limit === null || $limit === '') {
            return null;
        }

        $parsed = (int) $limit;

        return $parsed > 0 ? $parsed : null;
    }
}
