<?php

namespace App\Services\Reminders;

use App\Enums\ReminderStatus;
use App\Enums\TodoStatus;
use App\Models\Reminder;
use App\Models\Todo;
use App\Models\User;
use App\Notifications\TodoReminderDueNotification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReminderDispatchService
{
    public const DEFAULT_LIMIT = 50;

    public const MAX_ATTEMPTS = 5;

    /** @var list<int> */
    public const BACKOFF_SECONDS = [30, 120, 600, 1800];

    private const STALE_PROCESSING_MINUTES = 5;

    /**
     * @return array{claimed: int, dispatched: int, skipped: int, failed: int, retried: int}
     */
    public function dispatchDue(?int $limit = null): array
    {
        $limit = max(1, $limit ?? self::DEFAULT_LIMIT);

        $this->recoverStaleProcessingReminders();

        $stats = [
            'claimed' => 0,
            'dispatched' => 0,
            'skipped' => 0,
            'failed' => 0,
            'retried' => 0,
        ];

        $candidates = $this->candidateReminders($limit);

        foreach ($candidates as $candidate) {
            $claimed = $this->claimReminder($candidate);

            if ($claimed === null) {
                continue;
            }

            $stats['claimed']++;

            $outcome = $this->processClaimedReminder($claimed);

            $stats[$outcome]++;
        }

        return $stats;
    }

    /**
     * @return EloquentCollection<int, Reminder>
     */
    private function candidateReminders(int $limit): EloquentCollection
    {
        /** @var EloquentCollection<int, Reminder> $rows */
        $rows = Reminder::query()
            ->where('status', ReminderStatus::Pending->value)
            ->where('remind_at', '<=', now())
            ->orderBy('remind_at')
            ->orderBy('id')
            ->limit($limit * 3)
            ->get();

        return $rows
            ->filter(fn (Reminder $reminder): bool => $this->isAvailableForDispatch($reminder))
            ->take($limit)
            ->values();
    }

    private function claimReminder(Reminder $candidate): ?Reminder
    {
        return DB::transaction(function () use ($candidate): ?Reminder {
            /** @var Reminder|null $reminder */
            $reminder = Reminder::query()
                ->whereKey($candidate->id)
                ->lockForUpdate()
                ->first();

            if ($reminder === null || $reminder->status !== ReminderStatus::Pending) {
                return null;
            }

            if (! $this->isAvailableForDispatch($reminder)) {
                return null;
            }

            if ($reminder->remind_at !== null && $reminder->remind_at->gt(now())) {
                return null;
            }

            $metadata = $reminder->metadata ?? [];
            $attempts = (int) ($metadata['attempts'] ?? 0) + 1;

            $reminder->fill([
                'status' => ReminderStatus::Processing,
                'metadata' => array_merge($metadata, [
                    'attempts' => $attempts,
                    'claimed_at' => now()->toIso8601String(),
                    'available_at' => null,
                    'last_error' => null,
                ]),
            ])->save();

            return $reminder->fresh(['user', 'remindable']);
        });
    }

    /**
     * @return 'dispatched'|'skipped'|'failed'|'retried'
     */
    private function processClaimedReminder(Reminder $reminder): string
    {
        try {
            if ($reminder->notification_id) {
                $this->markDispatched($reminder, (string) $reminder->notification_id);

                return 'dispatched';
            }

            $existingNotification = $this->findExistingNotification($reminder);

            if ($existingNotification !== null) {
                $this->markDispatched($reminder, (string) $existingNotification->id);

                Log::info('reminders.dispatch.recovered_existing_notification', [
                    'reminder_id' => $reminder->id,
                    'notification_id' => $existingNotification->id,
                    'idempotency_key' => $reminder->idempotency_key,
                ]);

                return 'dispatched';
            }

            $remindable = $reminder->remindable;

            if (! $remindable instanceof Todo) {
                $this->markSkipped($reminder, 'Reminder subject is missing or unsupported.');

                return 'skipped';
            }

            if (in_array($remindable->status, [TodoStatus::Cancelled, TodoStatus::Completed], true)) {
                $this->markSkipped($reminder, 'To-do is '.$remindable->status->value.'.');

                return 'skipped';
            }

            $user = $reminder->user;

            if ($user === null || ! $user->is_active) {
                $this->markSkipped($reminder, 'Reminder target user is missing or inactive.');

                return 'skipped';
            }

            $user->notify(new TodoReminderDueNotification($remindable, $reminder));

            $notification = $this->findExistingNotification($reminder);

            if ($notification === null) {
                throw new \RuntimeException('Database notification was not persisted for reminder.');
            }

            $this->markDispatched($reminder, (string) $notification->id);

            Log::info('reminders.dispatch.dispatched', [
                'reminder_id' => $reminder->id,
                'todo_id' => $remindable->id,
                'user_id' => $user->id,
                'notification_id' => $notification->id,
                'idempotency_key' => $reminder->idempotency_key,
            ]);

            return 'dispatched';
        } catch (Throwable $exception) {
            return $this->handleDispatchFailure($reminder, $exception);
        }
    }

    /**
     * @return 'failed'|'retried'
     */
    private function handleDispatchFailure(Reminder $reminder, Throwable $exception): string
    {
        $metadata = $reminder->metadata ?? [];
        $attempts = (int) ($metadata['attempts'] ?? 1);

        Log::warning('reminders.dispatch.failed', [
            'reminder_id' => $reminder->id,
            'idempotency_key' => $reminder->idempotency_key,
            'attempts' => $attempts,
            'error' => $exception->getMessage(),
        ]);

        if ($attempts >= self::MAX_ATTEMPTS) {
            $reminder->fill([
                'status' => ReminderStatus::Failed,
                'metadata' => array_merge($metadata, [
                    'attempts' => $attempts,
                    'last_error' => $exception->getMessage(),
                    'failed_at' => now()->toIso8601String(),
                    'available_at' => null,
                ]),
            ])->save();

            return 'failed';
        }

        $availableAt = $this->nextAvailableAt($attempts);

        $reminder->fill([
            'status' => ReminderStatus::Pending,
            'metadata' => array_merge($metadata, [
                'attempts' => $attempts,
                'last_error' => $exception->getMessage(),
                'available_at' => $availableAt->toIso8601String(),
            ]),
        ])->save();

        return 'retried';
    }

    private function markDispatched(Reminder $reminder, string $notificationId): void
    {
        $metadata = $reminder->metadata ?? [];

        $reminder->fill([
            'status' => ReminderStatus::Dispatched,
            'dispatched_at' => now(),
            'notification_id' => $notificationId,
            'metadata' => array_merge($metadata, [
                'available_at' => null,
                'last_error' => null,
                'dispatched_at' => now()->toIso8601String(),
            ]),
        ])->save();
    }

    private function markSkipped(Reminder $reminder, string $reason): void
    {
        $metadata = $reminder->metadata ?? [];

        $reminder->fill([
            'status' => ReminderStatus::Skipped,
            'metadata' => array_merge($metadata, [
                'skip_reason' => $reason,
                'skipped_at' => now()->toIso8601String(),
                'available_at' => null,
            ]),
        ])->save();

        Log::info('reminders.dispatch.skipped', [
            'reminder_id' => $reminder->id,
            'idempotency_key' => $reminder->idempotency_key,
            'reason' => $reason,
        ]);
    }

    private function findExistingNotification(Reminder $reminder): ?DatabaseNotification
    {
        /** @var User|null $user */
        $user = $reminder->user ?? User::query()->find($reminder->user_id);

        if ($user === null) {
            return null;
        }

        /** @var DatabaseNotification|null $byReminderId */
        $byReminderId = $user->notifications()
            ->where('type', TodoReminderDueNotification::class)
            ->where('data->reminder_id', $reminder->id)
            ->latest('created_at')
            ->first();

        if ($byReminderId !== null) {
            return $byReminderId;
        }

        /** @var DatabaseNotification|null $byKey */
        $byKey = $user->notifications()
            ->where('type', TodoReminderDueNotification::class)
            ->where('data->idempotency_key', $reminder->idempotency_key)
            ->latest('created_at')
            ->first();

        return $byKey;
    }

    private function isAvailableForDispatch(Reminder $reminder): bool
    {
        $availableAt = $reminder->metadata['available_at'] ?? null;

        if (! is_string($availableAt) || $availableAt === '') {
            return true;
        }

        try {
            return Carbon::parse($availableAt)->lte(now());
        } catch (Throwable) {
            return true;
        }
    }

    private function nextAvailableAt(int $attempts): Carbon
    {
        $index = max(0, min($attempts - 1, count(self::BACKOFF_SECONDS) - 1));

        return now()->addSeconds(self::BACKOFF_SECONDS[$index]);
    }

    private function recoverStaleProcessingReminders(): void
    {
        $cutoff = now()->subMinutes(self::STALE_PROCESSING_MINUTES);

        $recovered = Reminder::query()
            ->where('status', ReminderStatus::Processing->value)
            ->where('updated_at', '<', $cutoff)
            ->update([
                'status' => ReminderStatus::Pending->value,
            ]);

        if ($recovered > 0) {
            Log::warning('reminders.dispatch.stale_processing_recovered', [
                'count' => $recovered,
                'cutoff' => $cutoff->toIso8601String(),
            ]);
        }
    }
}
