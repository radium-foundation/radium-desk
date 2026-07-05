<?php

namespace App\Data\Operations;

use App\Data\Operations\IraOperationalRecommendation;
use App\Data\Operations\IraOperationalRisk;
use App\Enums\IraNotificationType;

readonly class IraCommunicationInput
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public IraNotificationType $event,
        public ?IraOperationalRisk $insight = null,
        public ?IraOperationalRecommendation $recommendation = null,
        public array $context = [],
    ) {}

    public function dedupeKey(): string
    {
        if ($this->insight !== null) {
            return $this->insight->key;
        }

        if ($this->recommendation !== null) {
            return $this->recommendation->key;
        }

        $contextKey = $this->context['dedupe_key'] ?? null;

        if (is_string($contextKey) && $contextKey !== '') {
            return $contextKey;
        }

        return $this->event->value;
    }
}
