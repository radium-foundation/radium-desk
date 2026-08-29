<?php

namespace App\Services\Todos;

use App\Data\Telegram\TelegramOutboundMessage;
use App\Enums\NotificationCategory;
use App\Enums\NotificationChannelType;
use App\Models\Todo;
use App\Models\User;
use App\Notifications\TodoAssignedNotification;
use App\Services\AuditLogService;
use App\Services\Notifications\NotificationAuthorityService;
use App\Services\Telegram\TelegramBotService;
use App\Support\AppDateFormatter;
use App\Support\Telegram\TelegramOperationalLinkFormatter;
use App\Support\Telegram\TelegramTextLinkEntityBuilder;
use Illuminate\Support\Facades\Log;
use Throwable;

class TodoNotificationService
{
    public const EVENT_NOTIFIED = 'todo.notified';

    public function __construct(
        private readonly NotificationAuthorityService $notificationAuthority,
        private readonly TelegramBotService $telegramBot,
        private readonly AuditLogService $auditLogService,
        private readonly TelegramOperationalLinkFormatter $operationalLinkFormatter,
        private readonly TelegramTextLinkEntityBuilder $textLinkEntityBuilder,
    ) {}

    public function notifyAssigned(Todo $todo, User $actor, string $trigger): void
    {
        $todo->loadMissing(['assignee', 'creator', 'category']);

        $recipient = $todo->assignee;

        if ($recipient === null || ! $recipient->is_active) {
            return;
        }

        try {
            $this->dispatch($todo, $actor, $recipient, $trigger);
        } catch (Throwable $exception) {
            Log::warning('todo.notification.failed', [
                'todo_id' => $todo->id,
                'recipient_id' => $recipient->id,
                'trigger' => $trigger,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function dispatch(Todo $todo, User $actor, User $recipient, string $trigger): void
    {
        $inAppDelivered = false;
        $telegramDelivered = false;

        if ($this->notificationAuthority->shouldDeliver(
            $recipient,
            NotificationCategory::Assignment,
            NotificationChannelType::InApp,
        )) {
            $recipient->notify(new TodoAssignedNotification($todo, $actor, $trigger));
            $inAppDelivered = true;
        }

        if ($this->notificationAuthority->shouldDeliver(
            $recipient,
            NotificationCategory::Assignment,
            NotificationChannelType::Telegram,
        ) && $this->telegramBot->isConfigured()) {
            $payload = $this->formatTelegramPayload($todo, $actor, $recipient, $trigger);
            $sendResult = $this->telegramBot->sendMessage(
                chatId: (string) $recipient->telegram_chat_id,
                text: $payload->text,
                entities: $payload->entities,
            );
            $telegramDelivered = $sendResult->success;
        }

        if (! $inAppDelivered && ! $telegramDelivered) {
            return;
        }

        $this->auditLogService->log(
            userId: $actor->id,
            event: self::EVENT_NOTIFIED,
            auditable: $todo,
            newValues: [
                'recipient_id' => $recipient->id,
                'trigger' => $trigger,
                'in_app' => $inAppDelivered,
                'telegram' => $telegramDelivered,
            ],
        );
    }

    private function formatTelegramPayload(
        Todo $todo,
        User $actor,
        User $recipient,
        string $trigger,
    ): TelegramOutboundMessage {
        $heading = $trigger === 'created' ? 'To-Do created' : 'To-Do assigned';
        $title = $todo->title;
        $lines = [
            $heading,
            '',
            $title,
            'Assigned by: '.$actor->firstName(),
        ];

        if ($todo->category?->name) {
            $lines[] = 'Category: '.$todo->category->name;
        }

        $priority = $todo->priority?->label();
        if (is_string($priority) && $priority !== '') {
            $lines[] = 'Priority: '.$priority;
        }

        if ($todo->due_at !== null) {
            $lines[] = 'Due: '.(AppDateFormatter::datetime24($todo->due_at) ?? '—');
        }

        $url = $this->operationalLinkFormatter->todoLink($recipient, $todo);
        $text = implode("\n", $lines);

        if ($url === null) {
            return $this->textLinkEntityBuilder->messageWithTextLinks($text, []);
        }

        return $this->textLinkEntityBuilder->messageWithTextLinks($text, [
            ['text' => $title, 'url' => $url],
        ]);
    }
}
