<?php

namespace App\Enums;

enum QueueWorkerMode: string
{
    case Disabled = 'disabled';
    case Scheduler = 'scheduler';
    case DedicatedCron = 'dedicated_cron';
    case Supervisor = 'supervisor';
    case Horizon = 'horizon';

    public static function resolve(?string $mode, bool $legacyCronWorkerEnabled): self
    {
        if ($mode !== null && trim($mode) !== '') {
            return self::tryFrom(strtolower(trim($mode))) ?? self::Disabled;
        }

        return $legacyCronWorkerEnabled ? self::Scheduler : self::Disabled;
    }

    public static function fromConfig(): self
    {
        $value = config('infrastructure.queue_worker_mode', self::Disabled->value);

        return self::tryFrom((string) $value) ?? self::Disabled;
    }

    public function runsViaScheduler(): bool
    {
        return $this === self::Scheduler;
    }

    public function isActive(): bool
    {
        return $this !== self::Disabled;
    }
}
