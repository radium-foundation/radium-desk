<?php

namespace App\Console\Commands;

use App\Data\Retention\RetentionCategorySummary;
use App\Data\Retention\RetentionInspectionSummary;
use App\Services\Retention\RetentionInspectionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('database:retention-inspect {--dry-run : Read-only inspection (always enforced; no writes)}')]
#[Description('Inspect database retention candidates (read-only; no deletes)')]
class RetentionInspectCommand extends Command
{
    public function __construct(
        private readonly RetentionInspectionService $inspectionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $summary = $this->inspectionService->inspect();

        $this->info(sprintf(
            'Retention inspection at %s (read-only; zero database writes).',
            $summary->inspectedAt->toIso8601String(),
        ));
        $this->newLine();

        $rows = array_map(
            fn (RetentionCategorySummary $category): array => [
                $category->key,
                $category->table,
                $category->retentionDays === 0 ? 'immediate' : (string) $category->retentionDays,
                $category->cutoffAt ?? 'now',
                number_format($category->candidateCount),
                number_format($category->tableTotalCount),
            ],
            $summary->categories,
        );

        $this->table(
            ['Category', 'Table', 'Policy (days)', 'Cutoff (UTC)', 'Candidates', 'Table total'],
            $rows,
        );

        $this->newLine();
        $this->info(sprintf('Total retention candidates: %s', number_format($summary->totalCandidates)));
        $this->line('No rows were deleted. Future prune commands should use chunkById(), withoutOverlapping(), and explicit policies from config/retention.php.');

        Log::info('database.retention_inspect.completed', [
            'inspected_at' => $summary->inspectedAt->toIso8601String(),
            'total_candidates' => $summary->totalCandidates,
            'categories' => array_map(
                static fn (RetentionCategorySummary $category): array => [
                    'key' => $category->key,
                    'table' => $category->table,
                    'retention_days' => $category->retentionDays,
                    'candidate_count' => $category->candidateCount,
                    'table_total_count' => $category->tableTotalCount,
                ],
                $summary->categories,
            ),
        ]);

        return self::SUCCESS;
    }
}
