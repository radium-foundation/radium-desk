<?php

namespace App\Data;

use Illuminate\Support\Carbon;

readonly class RecentActivityItem
{
    private const REDUNDANT_CHIPS = [
        'Team',
        'Customer',
        'Communication',
        'Activity',
        'Notification',
        'Status',
        'Order',
        'Sync',
    ];

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

    public function primaryName(): string
    {
        if ($this->stream === 'customer' && filled($this->customerName)) {
            return (string) $this->customerName;
        }

        if ($this->stream === 'ira') {
            return filled($this->customerName) ? (string) $this->customerName : 'IRA';
        }

        if (filled($this->actorName) && $this->actorName !== 'IRA') {
            return $this->actorName;
        }

        if (filled($this->customerName)) {
            return (string) $this->customerName;
        }

        return $this->stream === 'ira' ? 'IRA' : 'Team';
    }

    public function icon(): string
    {
        $pill = strtolower((string) $this->typePill);
        $title = strtolower($this->title);

        return match (true) {
            $this->stream === 'ira' || $pill === 'ira' || str_contains($title, 'ira') => '🤖',
            $pill === 'whatsapp' || str_contains($title, 'whatsapp') => '💬',
            $pill === 'email' || str_contains($title, 'email') => '📨',
            $pill === 'payment' || str_contains($title, 'payment') => '💰',
            $pill === 'refund' || str_contains($title, 'refund') => '💰',
            $pill === 'assignment' || str_contains($title, 'assign') => '👤',
            $pill === 'remark' || str_contains($title, 'remark') => '📝',
            $pill === 'ivr' || str_contains($title, 'call') || str_contains($title, 'missed') => '📞',
            str_contains($title, 'closed') || str_contains($title, 'reject') => '❌',
            str_contains($title, 'reopen') => '🔄',
            $this->indicatorVariant === 'communication' || str_contains($title, 'communication') => '📨',
            $this->indicatorVariant === 'warning' => '⏳',
            $this->indicatorVariant === 'success' => '✅',
            $this->indicatorVariant === 'error' || $this->indicatorVariant === 'danger' => '❌',
            default => '•',
        };
    }

    /**
     * Chips that add information beyond section + title + icon.
     *
     * @return list<string>
     */
    public function chips(): array
    {
        if (! filled($this->typePill)) {
            return [];
        }

        $pill = (string) $this->typePill;

        if (in_array($pill, self::REDUNDANT_CHIPS, true)) {
            return [];
        }

        if ($this->stream === 'ira' && $pill === 'IRA') {
            return [];
        }

        return [$pill];
    }
}
