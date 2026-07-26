<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Collection;

class SystemSettingsAdminCollection
{
    /** @var Collection<string, SystemSetting>|null */
    private ?Collection $rows = null;

    /**
     * @return Collection<string, SystemSetting>
     */
    public function rows(): Collection
    {
        if ($this->rows === null) {
            $this->rows = SystemSetting::query()
                ->with('updatedBy')
                ->get()
                ->keyBy('key');
        }

        return $this->rows;
    }

    public function loaded(): bool
    {
        return $this->rows !== null;
    }

    public function invalidate(): void
    {
        $this->rows = null;
    }
}
