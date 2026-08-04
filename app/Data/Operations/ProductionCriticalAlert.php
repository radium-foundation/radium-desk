<?php

namespace App\Data\Operations;

readonly class ProductionCriticalAlert
{
    /**
     * @param  list<string>  $orderIds
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $message,
        public int $affectedCount = 0,
        public array $orderIds = [],
    ) {}

    public function fingerprint(): string
    {
        return hash('sha256', $this->key.'|'.$this->message.'|'.$this->affectedCount);
    }

    /**
     * Numeric severity used for Telegram escalation (higher = worse).
     */
    public function severity(): int
    {
        return max(0, $this->affectedCount);
    }

    /**
     * @return array<string, mixed>
     */
    public function toContext(): array
    {
        return [
            'label' => $this->label,
            'message' => $this->message,
            'affected_count' => $this->affectedCount,
            'order_ids' => $this->orderIds,
            'dedupe_key' => 'watchdog:'.$this->key,
            'fingerprint' => $this->fingerprint(),
            'severity' => $this->severity(),
        ];
    }
}
