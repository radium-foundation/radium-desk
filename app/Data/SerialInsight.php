<?php

namespace App\Data;

use App\Enums\SerialInsightConfidence;
use App\Enums\SerialInsightStatus;

readonly class SerialInsight
{
    public function __construct(
        public SerialInsightStatus $status,
        public SerialInsightConfidence $confidence,
        public string $explanation,
        public ?string $suggestedAction = null,
        public ?string $technicalReason = null,
    ) {}

    public function isActionable(): bool
    {
        return in_array($this->status, [
            SerialInsightStatus::Missing,
            SerialInsightStatus::Warning,
            SerialInsightStatus::Suspicious,
        ], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'confidence' => $this->confidence->value,
            'confidence_label' => $this->confidence->label(),
            'explanation' => $this->explanation,
            'suggested_action' => $this->suggestedAction,
            'technical_reason' => $this->technicalReason,
            'is_actionable' => $this->isActionable(),
        ];
    }
}
