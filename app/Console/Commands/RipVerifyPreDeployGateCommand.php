<?php

namespace App\Console\Commands;

use App\Services\Release\RipPreDeployGate;
use Illuminate\Console\Command;
use Throwable;

class RipVerifyPreDeployGateCommand extends Command
{
    protected $signature = 'rip:verify-pre-deploy-gate
                            {path : Checkpoint directory}
                            {--production-build= : Current production release.json build (short or full commit)}
                            {--git-sha= : Deprecated alias for --production-build}
                            {--checkpoint-id= : Optional expected checkpoint_id}';

    protected $description = 'Verify production-baseline RIP gate (checkpoint matches live release.json build before deploy)';

    public function handle(RipPreDeployGate $gate): int
    {
        $productionBuild = (string) ($this->option('production-build') ?: $this->option('git-sha'));

        if ($productionBuild === '') {
            $this->error('--production-build is required (current production release.json build, not the deploy target commit)');

            return self::FAILURE;
        }

        if ($this->option('git-sha') && ! $this->option('production-build')) {
            $this->warn('--git-sha is deprecated; use --production-build (current production baseline).');
        }

        try {
            $result = $gate->assertProductionBaselineCheckpoint(
                (string) $this->argument('path'),
                $productionBuild,
                $this->option('checkpoint-id') ? (string) $this->option('checkpoint-id') : null,
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Production-baseline RIP gate: PASS');
        $this->line('checkpoint_id='.$result['checkpoint_id']);

        return self::SUCCESS;
    }
}
