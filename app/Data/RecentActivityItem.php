<?php

namespace App\Data;

use Illuminate\Support\Carbon;

readonly class RecentActivityItem
{
    public function __construct(
        public string $stream,
        public string $title,
        public ?string $typePill,
        public string $indicatorVariant,
        public ?string $incidentReference,
        public ?string $orderReference,
        public ?string $customerName,
        public ?int $entityIncidentId,
        public ?string $entityReference,
        public Carbon $occurredAt,
        public string $compactTime,
        public string $exactTime,
        public string $actorName,
        public bool $isAutomation,
    ) {}

    public function incidentLabel(): string
    {
        if (filled($this->incidentReference) && filled($this->orderReference)) {
            return $this->incidentReference.' • '.$this->orderReference;
        }

        if (filled($this->incidentReference)) {
            return (string) $this->incidentReference;
        }

        if (filled($this->orderReference)) {
            return (string) $this->orderReference;
        }

        return '';
    }

    /**
     * @return list<string>
     */
    public function chips(): array
    {
        $streamPill = match ($this->stream) {
            'customer' => 'Customer',
            'team' => 'Team',
            'ira' => 'IRA',
            default => null,
        };

        $chips = array_values(array_filter([
            $this->typePill,
            $streamPill,
            $this->stream !== 'ira' && $this->actorName !== '' && $this->actorName !== 'IRA'
                ? $this->actorName
                : null,
        ], fn (?string $chip): bool => filled($chip)));

        return array_values(array_unique($chips));
    }
}
