<?php

namespace App\Data;

use App\Enums\NotificationPriority;

readonly class RealtimeNotification
{
    /**
     * @param  list<array{label: string, url: string}>  $actions
     * @param  array<string, mixed>|null  $interaction
     * @param  array<string, mixed>|null  $call
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $title,
        public string $message,
        public NotificationPriority $priority,
        public string $icon,
        public string $actionUrl,
        public ?string $deduplicationKey = null,
        public ?array $interaction = null,
        public ?array $call = null,
        public bool $playSound = false,
        public bool $browserNotification = true,
        public bool $showToast = true,
        public ?int $toastDurationMs = null,
        public ?string $expiresAt = null,
        public array $actions = [],
        public bool $requiresAcknowledgement = false,
        public ?int $unreadCount = null,
        public ?string $bellHtml = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toBroadcastPayload(): array
    {
        $payload = [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'message' => $this->message,
            'priority' => $this->priority->value,
            'icon' => $this->icon,
            'action_url' => $this->actionUrl,
            'deduplication_key' => $this->deduplicationKey,
            'play_sound' => $this->playSound,
            'browser_notification' => $this->browserNotification,
            'show_toast' => $this->showToast,
            'toast_duration_ms' => $this->toastDurationMs,
            'expires_at' => $this->expiresAt,
            'actions' => $this->actions,
            'requires_acknowledgement' => $this->requiresAcknowledgement,
        ];

        if ($this->interaction !== null) {
            $payload['interaction'] = $this->interaction;
        }

        if ($this->call !== null) {
            $payload['call'] = $this->call;
        }

        if ($this->unreadCount !== null) {
            $payload['unread_count'] = $this->unreadCount;
        }

        if ($this->bellHtml !== null) {
            $payload['bell_html'] = $this->bellHtml;
        }

        return $payload;
    }
}
