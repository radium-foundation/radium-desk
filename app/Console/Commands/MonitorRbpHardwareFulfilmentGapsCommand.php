<?php

namespace App\Console\Commands;

use App\Services\HardwareFulfilment\RbpHardwareFulfilmentGapMonitor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('desk:monitor-rbp-hardware-fulfilment-gaps
    {--limit=100 : Maximum gap rows to report}
    {--json : Emit machine-readable JSON only}')]
#[Description('Report paid RBP commerce orders missing hardware fulfilment after the configured grace window (read-only).')]
class MonitorRbpHardwareFulfilmentGapsCommand extends Command
{
    public function handle(RbpHardwareFulfilmentGapMonitor $monitor): int
    {
        if (! config('hardware_fulfilment.rbp_gap_monitor.enabled', true)) {
            $this->warn('RBP hardware fulfilment gap monitor is disabled.');

            return self::SUCCESS;
        }

        $limit = $this->option('limit');
        $limit = is_numeric($limit) ? max(1, (int) $limit) : 100;

        $gaps = $monitor->findGaps($limit);
        $dupCommerce = $monitor->findDuplicateFulfilmentCorrelationWarnings();

        $payload = [
            'scanned_at' => now()->toIso8601String(),
            'grace_minutes' => (int) config('hardware_fulfilment.rbp_gap_monitor.grace_minutes', 30),
            'gap_count' => count($gaps),
            'gaps' => $gaps,
            'duplicate_commerce_source_warnings' => $dupCommerce,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf('RBP fulfilment gaps: %d (grace %dm)', count($gaps), $payload['grace_minutes']));
            foreach ($gaps as $gap) {
                $this->line(sprintf(
                    '- %s %s commerce_id=%d created=%s',
                    $gap['source_id'],
                    $gap['commerce_order_no'],
                    $gap['commerce_order_id'],
                    $gap['commerce_created_at'],
                ));
            }
            if ($dupCommerce !== []) {
                $this->warn('Duplicate commerce rows for RBP source ids: '.count($dupCommerce));
            }
        }

        if ($gaps !== []) {
            Log::warning('[RBP hardware fulfilment gap monitor] Missing fulfilment rows detected.', [
                'gap_count' => count($gaps),
                'source_ids' => array_column($gaps, 'source_id'),
            ]);
        } else {
            Log::info('[RBP hardware fulfilment gap monitor] No gaps detected.');
        }

        return self::SUCCESS;
    }
}