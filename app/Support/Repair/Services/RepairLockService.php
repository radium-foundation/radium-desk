<?php

namespace App\Support\Repair\Services;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class RepairLockService
{
    /** @var list<Lock> */
    private array $heldLocks = [];

    public function acquire(string $repairKey): void
    {
        $ttl = max(30, (int) config('repair.lock_ttl_seconds', 180));

        if (config('repair.require_global_lock', true)) {
            $global = Cache::lock('system_repair:global', $ttl);
            if (! $global->get()) {
                throw new RuntimeException('Another repair operation is in progress (global lock).');
            }
            $this->heldLocks[] = $global;
        }

        $repairLock = Cache::lock('system_repair:'.$repairKey, $ttl);
        if (! $repairLock->get()) {
            $this->release();
            throw new RuntimeException(sprintf(
                'Another "%s" repair is already running.',
                $repairKey,
            ));
        }

        $this->heldLocks[] = $repairLock;
    }

    public function release(): void
    {
        while ($this->heldLocks !== []) {
            $lock = array_pop($this->heldLocks);
            $lock->release();
        }
    }
}
