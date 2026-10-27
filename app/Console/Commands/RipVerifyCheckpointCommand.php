<?php

namespace App\Console\Commands;

use App\Services\Release\RipCheckpointVerifier;
use Illuminate\Console\Command;
use Throwable;

class RipVerifyCheckpointCommand extends Command
{
    protected $signature = 'rip:verify-checkpoint {path : Checkpoint directory}';

    protected $description = 'Verify RIP checkpoint integrity (manifest, checksums, status rules)';

    public function handle(RipCheckpointVerifier $verifier): int
    {
        try {
            $result = $verifier->verifyDirectory((string) $this->argument('path'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $manifest = $result['manifest'];
        $this->info('RIP checkpoint verification: PASS');
        $this->line('checkpoint_id='.($manifest['checkpoint_id'] ?? ''));
        $this->line('status='.($manifest['status'] ?? ''));
        $this->line('checksum_ok='.($result['checksum_ok'] ? 'true' : 'false'));

        return self::SUCCESS;
    }
}
