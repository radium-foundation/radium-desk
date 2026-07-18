<?php

namespace App\Data;

use App\Enums\AlertSeverity;
use App\Enums\NotificationCategory;

readonly class OperatorAlert
{
    /**
     * @param  array<string, mixed>|null  $interaction
     */
    public function __construct(
        public string $title,
        public string $message,
        public AlertSeverity $severity,
        public NotificationCategory $category,
        public string $icon,
        public string $actionUrl,
        public ?string $entityType,
        public int|string|null $entityId,
        public string $deduplicationKey,
        public ?array $interaction = null,
        public bool $desktopPopup = true,
        public bool $playSound = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toBroadcastPayload(): array
    {
        $payload = [
            'title' => $this->title,
            'message' => $this->message,
            'severity' => $this->severity->value,
            'category' => $this->category->value,
            'icon' => $this->icon,
            'action_url' => $this->actionUrl,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'deduplication_key' => $this->deduplicationKey,
            'desktop_popup' => $this->desktopPopup,
            'play_sound' => $this->playSound,
        ];

        if ($this->interaction !== null) {
            $payload['interaction'] = $this->interaction;
        }

        return $payload;
    }
}
