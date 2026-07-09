<?php

namespace App\Services;

use App\Enums\WaitingReason;
use App\Exceptions\ActiveWaitingStateExistsException;
use App\Exceptions\InvalidAutomationPolicyException;
use App\Exceptions\UnknownAutomationPolicyException;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\User;
use App\Notifications\ServiceCaseCustomerRespondedNotification;
use App\Services\Automation\CustomerWaitingLifecycleService;
use App\Services\Dashboard\DashboardSnapshotStore;
use App\Services\Interakt\RequestSerialCommunicationHistoryService;
use App\Support\AppDateFormatter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class IncidentWaitingStateService
{
    public function activeFor(Incident $incident): ?IncidentWaitingState
    {
        return $incident->relationLoaded('activeWaitingState')
            ? $incident->activeWaitingState
            : IncidentWaitingState::query()
                ->where('incident_id', $incident->id)
                ->active()
                ->first();
    }

    public function start(
        Incident $incident,
        WaitingReason $reason,
        User $actor,
        ?string $reminderPolicyKey = null,
        ?bool $pauseSla = null,
        ?Carbon $startedAt = null,
        ?array $metadata = null,
        ?Carbon $nextActionAt = null,
    ): IncidentWaitingState {
        return DB::transaction(function () use ($incident, $reason, $actor, $reminderPolicyKey, $pauseSla, $startedAt, $metadata, $nextActionAt): IncidentWaitingState {
            $existing = IncidentWaitingState::query()
                ->where('incident_id', $incident->id)
                ->active()
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                throw ActiveWaitingStateExistsException::forIncident($incident->id);
            }

            $reasonConfig = config("waiting_states.reasons.{$reason->value}", []);
            $policyKey = $reminderPolicyKey ?? ($reasonConfig['default_reminder_policy_key'] ?? null);
            $slaPaused = $pauseSla ?? (bool) ($reasonConfig['pause_sla'] ?? false);
            $startedAtResolved = $startedAt ?? now();

            $waitingState = IncidentWaitingState::query()->create([
                'incident_id' => $incident->id,
                'waiting_reason' => $reason,
                'started_at' => $startedAtResolved,
                'sla_paused' => $slaPaused,
                'reminder_policy_key' => $policyKey,
                'metadata' => $metadata,
                'next_action_at' => $nextActionAt ?? $this->resolveDefaultNextActionAt(
                    reason: $reason,
                    policyKey: $policyKey,
                    startedAt: $startedAtResolved,
                ),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $incident->unsetRelation('activeWaitingState');

            app(CustomerWaitingLifecycleService::class)->auditWaitingStarted($waitingState, $actor);

            return $waitingState;
        });
    }

    public function clearSerialWaitingForOrder(Order $order, User $actor): void
    {
        if (! $order->isSerialLocked()) {
            return;
        }

        $order->loadMissing('incidents.activeWaitingState');

        foreach ($order->incidents as $incident) {
            if (! $incident->isActive()) {
                continue;
            }

            $waitingState = $this->activeFor($incident);

            if ($waitingState === null || $waitingState->waiting_reason !== WaitingReason::SerialNumber) {
                continue;
            }

            $this->clear($incident, $actor);
            $this->wakeOwnerAfterCustomerResponse(
                incident: $incident->fresh(['assignee', 'order', 'activeWaitingState', 'supportAppointments']),
                reason: WaitingReason::SerialNumber,
            );
        }
    }

    public function resolveDefaultNextActionAt(
        WaitingReason $reason,
        ?string $policyKey,
        Carbon $startedAt,
    ): Carbon {
        if ($reason === WaitingReason::SerialNumber) {
            $hours = max(1, (int) config('missing_serial.reminder_delay_hours', 24));

            return $startedAt->copy()->addHours($hours);
        }

        if ($policyKey !== null && $policyKey !== '') {
            try {
                $policy = app(AutomationPolicyService::class)->load($policyKey);
                $firstFollowUpDay = collect($policy->schedule)
                    ->map(fn ($entry) => $entry->day)
                    ->filter(fn (int $day): bool => $day > 0)
                    ->min();

                if (is_int($firstFollowUpDay)) {
                    return $startedAt->copy()->addDays($firstFollowUpDay);
                }
            } catch (UnknownAutomationPolicyException|InvalidAutomationPolicyException) {
                // Fall through to default hours.
            }
        }

        $defaultHours = max(1, (int) config('waiting_states.default_follow_up_hours', 24));

        return $startedAt->copy()->addHours($defaultHours);
    }

    public function wakeOwnerAfterCustomerResponse(Incident $incident, WaitingReason $reason): void
    {
        $assignee = $incident->assignee;

        if ($assignee === null || ! $assignee->is_active || $assignee->trashed()) {
            return;
        }

        $assignee->notify(new ServiceCaseCustomerRespondedNotification($incident, $reason));

        app(DashboardSnapshotStore::class)->forget();
    }

    public function ensureSerialWaitingState(Incident $incident, User $actor): IncidentWaitingState
    {
        $existing = $this->activeFor($incident);

        if ($existing !== null) {
            return $existing;
        }

        return $this->start(
            incident: $incident,
            reason: WaitingReason::SerialNumber,
            actor: $actor,
            pauseSla: true,
        );
    }

    public function clear(Incident $incident, User $actor, ?Carbon $clearedAt = null): IncidentWaitingState
    {
        return DB::transaction(function () use ($incident, $actor, $clearedAt): IncidentWaitingState {
            $waitingState = IncidentWaitingState::query()
                ->where('incident_id', $incident->id)
                ->active()
                ->lockForUpdate()
                ->firstOrFail();

            $waitingState->fill([
                'cleared_at' => $clearedAt ?? now(),
                'updated_by' => $actor->id,
            ])->save();

            $incident->unsetRelation('activeWaitingState');

            return $waitingState->refresh();
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function customer360Card(Incident $incident): ?array
    {
        $waitingState = $this->activeFor($incident);

        if ($waitingState === null) {
            return null;
        }

        return [
            'reason_label' => $waitingState->waiting_reason->label(),
            'started_at' => $waitingState->started_at,
            'customer_waiting_since' => $waitingState->customerWaitingSince(),
            'customer_followup_sent_at' => $waitingState->customer_followup_sent_at,
            'waiting_duration_label' => AppDateFormatter::waitingDuration($waitingState->started_at),
            'requested_at_label' => AppDateFormatter::format(
                $waitingState->started_at,
                RequestSerialCommunicationHistoryService::LAST_SENT_DISPLAY_FORMAT,
            ),
            'followup_sent_at_label' => $waitingState->customer_followup_sent_at !== null
                ? AppDateFormatter::format(
                    $waitingState->customer_followup_sent_at,
                    RequestSerialCommunicationHistoryService::LAST_SENT_DISPLAY_FORMAT,
                )
                : null,
            'sla_paused' => $waitingState->sla_paused,
            'reminder_policy_label' => $waitingState->reminderPolicyLabel(),
            'next_action_at' => $waitingState->next_action_at,
            'lifecycle_history' => app(CustomerWaitingLifecycleService::class)->lifecycleHistory($incident),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lifecycleOnlyCard(Incident $incident): ?array
    {
        $history = app(CustomerWaitingLifecycleService::class)->lifecycleHistory($incident);

        if ($history === null) {
            return null;
        }

        return [
            'lifecycle_history' => $history,
        ];
    }
}
