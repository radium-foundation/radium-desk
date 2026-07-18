<?php

namespace App\Services\Operations;

use App\Models\Incident;
use App\Models\User;
use App\Support\Operations\AppointmentReminderMessageContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class IraAssignmentTelegramBatchService
{
    private const INDEX_KEY = 'ira_assignment_batch:index';

    private const FLUSH_LOCK_KEY = 'ira_assignment_batch:flush';

    public function __construct(
        private readonly IraCommunicationService $communicationService,
    ) {}

    public function enqueue(User $assignee, Incident $incident): void
    {
        $incident->loadMissing('order');

        if (! config('ira.communication.assignment_telegram_batch.enabled', true)) {
            $this->communicationService->sendSmartAssignment(
                assignee: $assignee,
                customer: $incident->order?->customer_name ?? 'Unknown',
                device: $incident->order?->device_model ?? $incident->order?->product_name ?? 'Unknown',
                time: 'Unknown',
                caseReference: $incident->reference_no,
                context: [
                    'incident_id' => $incident->id,
                    'assigned_by' => 'IRA',
                    'task' => AppointmentReminderMessageContext::appointmentTypeLabel($incident),
                ],
            );

            return;
        }

        $delayMinutes = max(1, (int) config('ira.communication.assignment_telegram_batch.delay_minutes', 5));
        $userId = (int) $assignee->id;
        $lock = Cache::lock($this->userLockKey($userId), 10);

        $lock->block(5, function () use ($incident, $delayMinutes, $userId): void {
            $batchKey = $this->batchKey($userId);
            $existing = Cache::get($batchKey);
            $now = now();

            $item = [
                'incident_id' => $incident->id,
                'case' => $incident->reference_no,
                'task' => AppointmentReminderMessageContext::appointmentTypeLabel($incident),
                'customer' => $incident->order?->customer_name,
            ];

            if (! is_array($existing) || ($existing['items'] ?? null) === null) {
                $expiresAt = $now->copy()->addMinutes($delayMinutes);

                Cache::put($batchKey, [
                    'user_id' => $userId,
                    'items' => [$item],
                    'created_at' => $now->toIso8601String(),
                    'expires_at' => $expiresAt->toIso8601String(),
                ], $expiresAt->copy()->addHour());

                $this->addUserToIndex($userId);

                return;
            }

            $items = collect($existing['items'] ?? [])
                ->filter(fn (mixed $candidate): bool => is_array($candidate))
                ->reject(fn (array $candidate): bool => (int) ($candidate['incident_id'] ?? 0) === (int) $incident->id)
                ->values()
                ->all();

            $items[] = $item;

            $expiresAt = Carbon::parse((string) ($existing['expires_at'] ?? $now->copy()->addMinutes($delayMinutes)->toIso8601String()));

            Cache::put($batchKey, [
                'user_id' => $userId,
                'items' => $items,
                'created_at' => $existing['created_at'] ?? $now->toIso8601String(),
                'expires_at' => $expiresAt->toIso8601String(),
            ], $expiresAt->copy()->addHour());

            $this->addUserToIndex($userId);
        });
    }

    /**
     * Flush a pending batch immediately when the engineer opens Radium Desk.
     */
    public function flushForUserIfPending(User $user): bool
    {
        if (! config('ira.communication.assignment_telegram_batch.enabled', true)) {
            return false;
        }

        if ($this->peek((int) $user->id) === null) {
            return false;
        }

        return $this->flushUser((int) $user->id, ignoreExpiry: true);
    }

    public function flushDue(): int
    {
        if (! config('ira.communication.assignment_telegram_batch.enabled', true)) {
            return 0;
        }

        $lock = Cache::lock(self::FLUSH_LOCK_KEY, 55);

        if (! $lock->get()) {
            return 0;
        }

        try {
            $userIds = $this->pendingUserIds();
            $flushed = 0;

            foreach ($userIds as $userId) {
                if ($this->flushUser((int) $userId, ignoreExpiry: false)) {
                    $flushed++;
                }
            }

            if ($flushed > 0) {
                Log::info('ira.assignment_telegram_batch.flushed', [
                    'batches' => $flushed,
                ]);
            }

            return $flushed;
        } finally {
            $lock->release();
        }
    }

    public function batchKey(int $userId): string
    {
        return 'ira_assignment_batch:'.$userId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function peek(int $userId): ?array
    {
        $batch = Cache::get($this->batchKey($userId));

        return is_array($batch) ? $batch : null;
    }

    private function flushUser(int $userId, bool $ignoreExpiry): bool
    {
        $userLock = Cache::lock($this->userLockKey($userId), 10);

        if (! $userLock->get()) {
            return false;
        }

        try {
            $batchKey = $this->batchKey($userId);
            $batch = Cache::get($batchKey);

            if (! is_array($batch)) {
                $this->removeUserFromIndex($userId);

                return false;
            }

            $expiresAt = Carbon::parse((string) ($batch['expires_at'] ?? ''));

            if (! $ignoreExpiry && $expiresAt->isFuture()) {
                return false;
            }

            $items = collect($batch['items'] ?? [])
                ->filter(fn (mixed $item): bool => is_array($item) && filled($item['case'] ?? null))
                ->values()
                ->all();

            // Clear cache before send so concurrent scheduler/login cannot double-send.
            Cache::forget($batchKey);
            $this->removeUserFromIndex($userId);

            if ($items === []) {
                return false;
            }

            $user = User::query()->whereKey($userId)->where('is_active', true)->first();

            if ($user === null) {
                return false;
            }

            $this->communicationService->sendIraAssignmentBatch(
                assignee: $user,
                items: $items,
                context: [
                    'incident_id' => $items[0]['incident_id'] ?? null,
                    'dedupe_key' => 'ira_assignment_batch:'.$userId.':'.$expiresAt->timestamp,
                ],
            );

            return true;
        } finally {
            $userLock->release();
        }
    }

    private function userLockKey(int $userId): string
    {
        return 'ira_assignment_batch:lock:'.$userId;
    }

    /**
     * @return list<int>
     */
    private function pendingUserIds(): array
    {
        $index = Cache::get(self::INDEX_KEY, []);

        if (! is_array($index)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $index)));
    }

    private function addUserToIndex(int $userId): void
    {
        $indexLock = Cache::lock('ira_assignment_batch:index_lock', 5);

        $indexLock->block(5, function () use ($userId): void {
            $index = $this->pendingUserIds();

            if (! in_array($userId, $index, true)) {
                $index[] = $userId;
            }

            Cache::put(self::INDEX_KEY, $index, now()->addDay());
        });
    }

    private function removeUserFromIndex(int $userId): void
    {
        $indexLock = Cache::lock('ira_assignment_batch:index_lock', 5);

        $indexLock->block(5, function () use ($userId): void {
            $index = array_values(array_filter(
                $this->pendingUserIds(),
                fn (int $id): bool => $id !== $userId,
            ));

            if ($index === []) {
                Cache::forget(self::INDEX_KEY);

                return;
            }

            Cache::put(self::INDEX_KEY, $index, now()->addDay());
        });
    }
}
