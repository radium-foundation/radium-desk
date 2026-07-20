<?php

namespace App\Data\Platform;

use App\Enums\PlatformHealthStatus;
use Illuminate\Support\Carbon;

readonly class PlatformHealthComponent
{
    /**
     * @param  array<string, mixed>  $metrics
     */
    public function __construct(
        public string $key,
        public string $label,
        public PlatformHealthStatus $status,
        public string $detail,
        public Carbon $checkedAt,
        public array $metrics = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'badge_class' => $this->status->badgeClass(),
            'detail' => $this->detail,
            'checked_at' => $this->checkedAt->toIso8601String(),
            'metrics' => $this->metrics,
        ];
    }
}
