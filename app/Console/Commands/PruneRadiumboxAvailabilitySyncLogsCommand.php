<?php

namespace App\Console\Commands;

use App\Models\InventoryAvailabilitySyncLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('radiumbox:prune-availability-sync-logs
    {--days=14 : Delete sync logs older than this many days}
    {--dry-run : Count eligible rows without deleting}')]
#[Description('Prune old RadiumBox availability sync logs. Does not change snapshots, stock, or mappings.')]
class PruneRadiumboxAvailabilitySyncLogsCommand extends Command
{
    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $query = InventoryAvailabilitySyncLog::query()->where('created_at', '<', $cutoff);
        $count = (clone $query)->count();

        if ((bool) $this->option('dry-run')) {
            $this->info(sprintf(
                'RadiumBox availability sync log prune dry run: eligible=%d older_than_days=%d.',
                $count,
                $days,
            ));

            return self::SUCCESS;
        }

        $deleted = $query->delete();

        $this->info(sprintf(
            'RadiumBox availability sync log prune completed: deleted=%d older_than_days=%d.',
            $deleted,
            $days,
        ));

        return self::SUCCESS;
    }
}
