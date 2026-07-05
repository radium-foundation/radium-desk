<?php

namespace App\Data\Operations;

use App\Enums\PerformanceInsightTone;

readonly class PerformanceInsight
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $message,
        public PerformanceInsightTone $tone,
        public array $context = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'message' => $this->message,
            'tone' => $this->tone->value,
            'badge_class' => $this->tone->badgeClass(),
            'context' => $this->context,
        ];
    }
}
