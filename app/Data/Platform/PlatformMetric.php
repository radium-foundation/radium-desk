<?php

namespace App\Data\Platform;

use App\Enums\PlatformHealthStatus;

readonly class PlatformMetric
{
    public function __construct(
        public string $key,
        public string $label,
        public string $value,
        public ?string $detail = null,
        public ?PlatformHealthStatus $status = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'value' => $this->value,
            'detail' => $this->detail,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'badge_class' => $this->status?->badgeClass(),
        ];
    }
}
