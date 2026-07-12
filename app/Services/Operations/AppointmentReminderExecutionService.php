<?php

namespace App\Services\Operations;

use App\Data\Operations\AppointmentReminderExecutionResult;
use App\Data\Operations\SupportAppointmentReminderCandidate;
use App\Enums\AutomationExecutionStatus;
use App\Enums\AutomationPolicyActionType;
use App\Enums\IraNotificationStatus;
use App\Models\AutomationExecution;
use App\Support\Operations\AppointmentReminderMessageContext;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

class AppointmentReminderExecutionService
{
    public const POLICY_KEY = 'appointment-reminder';

    public const ACTION_KEY = 'telegram';

    public const CHANNEL = 'telegram';

    public function __construct(
        private readonly AppointmentReminderIdempotencyKeyGenerator $idempotencyKeyGenerator,
        private readonly IraCommunicationService $communicationService,
    ) {}

    public function execute(SupportAppointmentReminderCandidate $candidate): AppointmentReminderExecutionResult
    {
        $scheduledDate = $candidate->appointment->preferred_date?->toDateString()
            ?? $candidate->startsAt->toDateString();

        $idempotencyKey = $this->idempotencyKeyGenerator->generate(
            appointmentId: $candidate->appointment->id,
            thresholdMinutes: $candidate->thresholdMinutes,
            scheduledDate: $scheduledDate,
        );

        $existing = AutomationExecution::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            return new AppointmentReminderExecutionResult(
                execution: $existing,
                status: $existing->status,
                skippedExisting: true,
                telegramSent: $existing->status === AutomationExecutionStatus::Success,
            );
        }

        try {
            $execution = AutomationExecution::query()->create(
                $this->executionAttributes(
                    candidate: $candidate,
                    idempotencyKey: $idempotencyKey,
                    status: AutomationExecutionStatus::Pending,
                ),
            );
        } catch (QueryException|UniqueConstraintViolationException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $existing = AutomationExecution::query()
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();

            return new AppointmentReminderExecutionResult(
                execution: $existing,
                status: $existing->status,
                skippedExisting: true,
                telegramSent: $existing->status === AutomationExecutionStatus::Success,
            );
        }

        return $this->deliverReminder($candidate, $execution);
    }

    private function deliverReminder(
        SupportAppointmentReminderCandidate $candidate,
        AutomationExecution $execution,
    ): AppointmentReminderExecutionResult {
        $notifications = $this->communicationService->sendSupportAppointmentReminder($candidate);
        $notification = $notifications[0] ?? null;
        $now = Carbon::now();

        if ($notification?->status === IraNotificationStatus::Sent) {
            $execution->update([
                'status' => AutomationExecutionStatus::Success,
                'external_id' => $notification->id !== null ? (string) $notification->id : null,
                'error_message' => null,
                'metadata' => $this->executionMetadata($candidate, $notification->id),
                'completed_at' => $now,
            ]);

            return new AppointmentReminderExecutionResult(
                execution: $execution->fresh(),
                status: AutomationExecutionStatus::Success,
                telegramSent: true,
            );
        }

        if ($notification?->status === IraNotificationStatus::Skipped) {
            $execution->update([
                'status' => AutomationExecutionStatus::Skipped,
                'external_id' => $notification->id !== null ? (string) $notification->id : null,
                'error_message' => $notification->error_message,
                'metadata' => $this->executionMetadata($candidate, $notification->id),
                'completed_at' => $now,
            ]);

            return new AppointmentReminderExecutionResult(
                execution: $execution->fresh(),
                status: AutomationExecutionStatus::Skipped,
            );
        }

        $execution->update([
            'status' => AutomationExecutionStatus::Failed,
            'external_id' => $notification?->id !== null ? (string) $notification->id : null,
            'error_message' => $notification?->error_message ?? 'Telegram appointment reminder failed.',
            'metadata' => $this->executionMetadata($candidate, $notification?->id),
            'completed_at' => $now,
        ]);

        return new AppointmentReminderExecutionResult(
            execution: $execution->fresh(),
            status: AutomationExecutionStatus::Failed,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function executionAttributes(
        SupportAppointmentReminderCandidate $candidate,
        string $idempotencyKey,
        AutomationExecutionStatus $status,
        ?string $errorMessage = null,
    ): array {
        $now = Carbon::now();

        return [
            'waiting_state_id' => null,
            'support_appointment_id' => $candidate->appointment->id,
            'policy_key' => self::POLICY_KEY,
            'schedule_step' => $candidate->thresholdMinutes,
            'action_type' => AutomationPolicyActionType::AppointmentReminderTelegram,
            'action_key' => self::ACTION_KEY,
            'channel' => self::CHANNEL,
            'status' => $status,
            'idempotency_key' => $idempotencyKey,
            'external_id' => null,
            'error_message' => $errorMessage,
            'metadata' => $this->executionMetadata($candidate),
            'started_at' => $now,
            'completed_at' => $status === AutomationExecutionStatus::Pending ? null : $now,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function executionMetadata(
        SupportAppointmentReminderCandidate $candidate,
        ?int $iraNotificationId = null,
    ): array {
        $order = $candidate->appointment->incident?->order;
        $appointmentType = AppointmentReminderMessageContext::appointmentTypeLabel($candidate->appointment->incident);

        return array_filter([
            'automation_type' => self::POLICY_KEY,
            'appointment_id' => $candidate->appointment->id,
            'engineer_id' => $candidate->engineer->id,
            'threshold_minutes' => $candidate->thresholdMinutes,
            'scheduled_date' => $candidate->appointment->preferred_date?->toDateString(),
            'appointment_type' => $appointmentType,
            'customer_name' => $order?->customer_name,
            'order_id' => $order?->order_id,
            'ira_notification_id' => $iraNotificationId,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function isUniqueConstraintViolation(QueryException|UniqueConstraintViolationException $exception): bool
    {
        if ($exception instanceof UniqueConstraintViolationException) {
            return true;
        }

        $sqlState = $exception->errorInfo[0] ?? null;

        return in_array($sqlState, ['23000', '23505', '2067'], true);
    }
}
