<?php

namespace App\Services\Platform\Health;

use App\Contracts\Platform\PlatformHealthProvider;
use App\Data\Platform\PlatformHealthComponent;
use App\Enums\PlatformHealthStatus;

class StorageHealthProvider implements PlatformHealthProvider
{
    public function key(): string
    {
        return 'storage';
    }

    public function label(): string
    {
        return 'Storage';
    }

    public function sortOrder(): int
    {
        return 70;
    }

    public function probe(): PlatformHealthComponent
    {
        $checkedAt = now();
        $paths = [
            'logs' => storage_path('logs'),
            'framework' => storage_path('framework'),
        ];

        $failures = [];

        foreach ($paths as $label => $path) {
            if (! is_dir($path) || ! is_writable($path)) {
                $failures[] = $label;
            }
        }

        if ($failures !== []) {
            return new PlatformHealthComponent(
                key: $this->key(),
                label: $this->label(),
                status: PlatformHealthStatus::Critical,
                detail: 'Storage path(s) not writable: '.implode(', ', $failures).'.',
                checkedAt: $checkedAt,
                metrics: [
                    'failed_paths' => $failures,
                ],
            );
        }

        return new PlatformHealthComponent(
            key: $this->key(),
            label: $this->label(),
            status: PlatformHealthStatus::Healthy,
            detail: 'Storage logs and framework directories are writable.',
            checkedAt: $checkedAt,
            metrics: [
                'checked_paths' => array_keys($paths),
            ],
        );
    }
}
