<?php

namespace App\Services\Platform\Warmers;

class ExecutiveSnapshotWarmer extends AbstractZoneSnapshotWarmer
{
    public function key(): string
    {
        return 'executive_snapshot';
    }

    public function label(): string
    {
        return 'Executive Snapshot';
    }

    public function priority(): int
    {
        return 20;
    }

    protected function zoneKey(): string
    {
        return 'executive_snapshot';
    }
}
