<?php

namespace App\Console\Commands;

use App\Services\Release\RipCheckpointIdPolicy;
use Illuminate\Console\Command;

class RipComposeCheckpointIdCommand extends Command
{
    protected $signature = 'rip:compose-checkpoint-id
                            {--change= : Short change slug (kebab-case)}
                            {--git-sha= : Git commit (full or short)}';

    protected $description = 'Compose a canonical RIP checkpoint ID for Radium Desk (dry-run, no capture)';

    public function handle(RipCheckpointIdPolicy $policy): int
    {
        $change = (string) $this->option('change');
        $gitSha = (string) $this->option('git-sha');

        if ($change === '' || $gitSha === '') {
            $this->error('Required: --change and --git-sha');

            return self::FAILURE;
        }

        $composed = $policy->compose($change, $gitSha);

        $this->line('checkpoint_id='.$composed['checkpoint_id']);
        $this->line('private_checkpoint_path=storage/app/private/release-checkpoints/'.$composed['checkpoint_id']);

        return self::SUCCESS;
    }
}
