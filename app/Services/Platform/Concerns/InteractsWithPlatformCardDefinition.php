<?php

namespace App\Services\Platform\Concerns;

use App\Data\Platform\PlatformCardDefinition;
use App\Data\Platform\PlatformCardPayload;
use App\Models\User;

trait InteractsWithPlatformCardDefinition
{
    abstract public function definition(): PlatformCardDefinition;

    abstract public function load(User $viewer): PlatformCardPayload;

    public function key(): string
    {
        return $this->definition()->id;
    }

    public function title(): string
    {
        return $this->definition()->title;
    }

    public function section(): string
    {
        return $this->definition()->section;
    }

    public function permission(): ?string
    {
        return $this->definition()->permission;
    }

    public function sortOrder(): int
    {
        return $this->definition()->priority;
    }

    public function authorize(User $viewer): bool
    {
        $permission = $this->definition()->permission;

        return $permission === null || $viewer->can($permission);
    }

    public function refresh(User $viewer): PlatformCardPayload
    {
        return $this->load($viewer);
    }

    public function payload(User $viewer): PlatformCardPayload
    {
        return $this->load($viewer);
    }
}
