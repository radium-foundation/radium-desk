<?php

namespace App\Console\Commands;

use App\Services\HardwareFulfilment\RbpHardwareFulfilmentGapMonitor;
use App\Services\HardwareFulfilment\RbpHardwareFulfilmentGapRecoveryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('desk:recover-rbp-hardware-fulfilment-gaps
    {--execute : Perform writes (default is dry-run)}
    {--force : Skip interactive confirmation when used with --execute}
    {--json : Emit machine-readable JSON only}
    {--source=* : Optional subset of allowlisted source ids (must still be on allowlist)}')]
#[Description('Recover allowlisted paid RBP hardware orders missing fulfilment (P-17-43; dry-run by default).')]
class RecoverRbpHardwareFulfilmentGapsCommand extends Command
{
    public function handle(
        RbpHardwareFulfilmentGapRecoveryService $recovery,
        RbpHardwareFulfilmentGapMonitor $monitor,
    ): int {
        $execute = (bool) $this->option('execute');
        $allowlist = $recovery->configuredAllowlist();

        if ($allowlist === []) {
            $this->error('Recovery allowlist is empty. Refuse to run.');

            return self::FAILURE;
        }

        $requested = $this->option('source');
        $targets = is_array($requested) && $requested !== []
            ? array_values(array_unique(array_map(static fn (mixed $id): string => strtoupper(trim((string) $id)), $requested)))
            : $allowlist;

        foreach ($targets as $id) {
            if (! in_array($id, $allowlist, true)) {
                $this->error('Requested source '.$id.' is not on the configured allowlist.');

                return self::FAILURE;
            }
        }

        if ($execute && ! (bool) $this->option('force')) {
            $expected = count($targets);
            if (! $this->confirm('Execute recovery for '.$expected.' allowlisted order(s)?', false)) {
                $this->warn('Recovery cancelled.');

                return self::SUCCESS;
            }
        }

        $results = [];
        foreach ($targets as $sourceId) {
            $results[] = $recovery->recoverOne($sourceId, $execute);
        }

        $gapsAfter = $monitor->findGaps(null);

        $payload = [
            'executed' => $execute,
            'allowlist_count' => count($allowlist),
            'target_count' => count($targets),
            'results' => $results,
            'gap_count_after' => count($gapsAfter),
            'scanned_at' => now()->toIso8601String(),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf(
                '%s recovery for %d target(s). Gaps remaining: %d',
                $execute ? 'Executed' : 'Dry-run',
                count($targets),
                count($gapsAfter),
            ));
            foreach ($results as $row) {
                $this->line(sprintf(
                    '- %s %s (%s) %s%s',
                    $row['source_id'],
                    $row['status'],
                    $row['code'],
                    $row['message'],
                    $row['hardware_fulfilment_id'] ? ' hf='.$row['hardware_fulfilment_id'] : '',
                ));
            }
        }

        $failed = array_filter($results, static fn (array $row): bool => $row['status'] === 'failed');

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
