<?php

namespace App\Services\MissingSerial;

use App\Data\MissingSerial\MissingSerialAutomationOrderResult;
use App\Data\MissingSerial\MissingSerialAutomationProcessResult;
use App\Data\NotificationMessage;
use App\Enums\MissingSerialAutomationAction;
use App\Enums\MissingSerialAutomationStatus;
use App\Enums\NotificationChannelType;
use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Enums\RefundStatus;
use App\Enums\WaitingReason;
use App\Enums\WhatsAppTemplate;
use App\Enums\WhatsAppTemplateDispatchStatus;
use App\Enums\WhatsAppTemplateTriggerSource;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Models\WhatsAppTemplateDispatch;
use App\Services\Automation\CustomerWaitingLifecycleService;
use App\Services\AutomationIdentityService;
use App\Services\IncidentWaitingStateService;
use App\Services\Notifications\NotificationChannelAvailabilityService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\RadiumBox\RadiumBoxService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Automates the missing-serial customer communication journey after online payment.
 *
 * Initial Request Serial Number outreach requires ALL of:
 * paid (Cashfree-verified) order, ~15 min delay, RadiumBox recovery attempted,
 * serial still unavailable, and no prior contact for this event.
 *
 * @see docs/missing-serial-automation.md
 */
class MissingSerialAutomationService
{
    public function __construct(
        private readonly MissingSerialAutomationAuditService $auditService,
        private readonly NotificationDispatcher $notificationDispatcher,
        private readonly NotificationChannelAvailabilityService $channelAvailabilityService,
        private readonly RadiumBoxService $radiumBoxService,
        private readonly IncidentWaitingStateService $waitingStateService,
        private readonly CustomerWaitingLifecycleService $customerWaitingLifecycleService,
        private readonly AutomationIdentityService $automationIdentityService,
    ) {}

    public function process(?int $limit = null): MissingSerialAutomationProcessResult
    {
        if (! config('missing_serial.enabled', true) || ! Order::supportsMissingSerialAutomationTracking()) {
            return new MissingSerialAutomationProcessResult(
                scanned: 0,
                sent: 0,
                reminded: 0,
                escalated: 0,
                skipped: 0,
                failed: 0,
            );
        }

        $limit ??= max(1, (int) config('missing_serial.batch_limit', 100));
        $scanned = 0;
        $sent = 0;
        $reminded = 0;
        $escalated = 0;
        $skipped = 0;
        $failed = 0;

        $orders = $this->prioritizedCandidateOrdersQuery()
            ->limit($limit)
            ->get();

        foreach ($orders as $order) {
            $scanned++;
            $result = $this->processOrder($order);

            match ($result->outcome) {
                'sent' => match ($result->action) {
                    MissingSerialAutomationAction::Request => $sent++,
                    MissingSerialAutomationAction::Reminder => $reminded++,
                    MissingSerialAutomationAction::Escalate => $escalated++,
                },
                'skipped' => $skipped++,
                'failed' => $failed++,
                default => null,
            };
        }

        return new MissingSerialAutomationProcessResult(
            scanned: $scanned,
            sent: $sent,
            reminded: $reminded,
            escalated: $escalated,
            skipped: $skipped,
            failed: $failed,
        );
    }

    public function processOrder(Order $order): MissingSerialAutomationOrderResult
    {
        $action = $this->determineAction($order);

        if ($action === null) {
            return new MissingSerialAutomationOrderResult(
                orderId: $order->id,
                action: MissingSerialAutomationAction::Request,
                outcome: 'skipped',
                message: $this->ineligibilityReason($order) ?? 'Not due for automation.',
            );
        }

        $incident = $this->resolveIncident($order);

        if ($incident === null) {
            return new MissingSerialAutomationOrderResult(
                orderId: $order->id,
                action: $action,
                outcome: 'skipped',
                message: 'No service case linked to order.',
            );
        }

        if ($action === MissingSerialAutomationAction::Escalate) {
            return $this->escalateOrder($order, $incident);
        }

        return $this->sendContact($order, $incident, $action);
    }

    public function determineAction(Order $order): ?MissingSerialAutomationAction
    {
        if ($this->ineligibilityReason($order) !== null) {
            return null;
        }

        $status = MissingSerialAutomationStatus::tryFrom((string) $order->missing_serial_automation_status);
        $paymentAt = $this->paymentReferenceAt($order);

        if ($paymentAt === null) {
            return null;
        }

        $firstDueAt = $paymentAt->copy()->addMinutes($this->firstDelayMinutes());

        if ($status === null) {
            return $firstDueAt->isPast() ? MissingSerialAutomationAction::Request : null;
        }

        if ($status === MissingSerialAutomationStatus::Completed
            || $status === MissingSerialAutomationStatus::Escalated) {
            return null;
        }

        $firstRequestedAt = $order->missing_serial_first_requested_at;

        if ($firstRequestedAt === null) {
            return $firstDueAt->isPast() ? MissingSerialAutomationAction::Request : null;
        }

        $reminderDueAt = $firstRequestedAt->copy()->addHours($this->reminderDelayHours());
        $escalationDueAt = $firstRequestedAt->copy()->addHours($this->escalationDelayHours());

        if ($status === MissingSerialAutomationStatus::Requested) {
            if ($escalationDueAt->isPast()) {
                return MissingSerialAutomationAction::Escalate;
            }

            if ($reminderDueAt->isPast()) {
                return MissingSerialAutomationAction::Reminder;
            }

            return null;
        }

        if ($status === MissingSerialAutomationStatus::Reminded) {
            return $escalationDueAt->isPast() ? MissingSerialAutomationAction::Escalate : null;
        }

        return null;
    }

    public function ineligibilityReason(Order $order): ?string
    {
        if (! config('missing_serial.enabled', true)) {
            return 'Automation is disabled.';
        }

        if (! Order::supportsMissingSerialAutomationTracking()) {
            return 'Automation tracking is unavailable.';
        }

        if ($order->isProductOrder()) {
            return 'Product orders are excluded from missing serial automation.';
        }

        if (! $order->isCashfreeVerified()) {
            return 'Order is not Cashfree verified.';
        }

        if (! $order->isMissingSerialNumber()) {
            return 'Serial number is already available.';
        }

        if (! $this->radiumBoxService->needsEnrichment($order)) {
            return 'Device recovery is complete.';
        }

        if (! $this->hasRadiumBoxEnrichmentAttempted($order)) {
            return 'RadiumBox enrichment has not been attempted.';
        }

        if ($this->isOrderBlocked($order)) {
            return 'Order is cancelled or refunded.';
        }

        if ($order->missing_serial_automation_status === MissingSerialAutomationStatus::Completed->value) {
            return 'Automation already completed.';
        }

        if ($order->missing_serial_automation_status === MissingSerialAutomationStatus::Escalated->value) {
            return 'Order already escalated to coordinator.';
        }

        return null;
    }

    public function markCompletedIfApplicable(Order $order, string $reason = 'serial_resolved'): void
    {
        if (! Order::supportsMissingSerialAutomationTracking()) {
            return;
        }

        $freshOrder = $order->fresh();

        if ($freshOrder === null) {
            return;
        }

        $this->waitingStateService->clearIdentityCorrectionWaitingWhenValidationPasses(
            order: $freshOrder,
            actor: $this->automationIdentityService->systemUser(),
            source: $reason,
        );

        if ($freshOrder->isMissingSerialNumber()) {
            return;
        }

        if ($freshOrder->missing_serial_automation_status === MissingSerialAutomationStatus::Completed->value) {
            return;
        }

        $freshOrder->update([
            'missing_serial_automation_status' => MissingSerialAutomationStatus::Completed->value,
        ]);

        $this->auditService->recordCompleted($freshOrder->fresh(), $reason);
    }

    /**
     * Candidate orders prioritized for outreach: untouched cases first, then oldest payment due date.
     *
     * @return Builder<Order>
     */
    public function prioritizedCandidateOrdersQuery(): Builder
    {
        return $this->candidateOrdersQuery()
            ->orderByRaw('CASE WHEN missing_serial_automation_status IS NULL THEN 0 ELSE 1 END')
            ->orderByRaw('COALESCE(payment_date, created_at) ASC')
            ->orderBy('id');
    }

    /**
     * @return Builder<Order>
     */
    public function candidateOrdersQuery(): Builder
    {
        return Order::query()
            ->cashfreeVerified()
            ->whereSerialMissing()
            ->where('status', OrderStatus::Active->value)
            ->where(function (Builder $query): void {
                $prefix = strtoupper((string) config('operations.hardware_order_prefix', 'RDE'));
                $query->where('order_id', 'not like', $prefix.'%');
            })
            ->where(function (Builder $query): void {
                $query->where('radiumbox_sync_attempts', '>', 0)
                    ->orWhere('radiumbox_sync_status', '!=', RadiumBoxEnrichmentSyncStatus::NotSynced->value)
                    ->orWhereNotNull('radiumbox_last_sync_at');
            })
            ->where(function (Builder $query): void {
                $query->whereNull('missing_serial_automation_status')
                    ->orWhereNotIn('missing_serial_automation_status', [
                        MissingSerialAutomationStatus::Completed->value,
                        MissingSerialAutomationStatus::Escalated->value,
                    ]);
            })
            ->whereDoesntHave('refundRequests', function (Builder $query): void {
                $query->where('status', RefundStatus::Approved->value);
            });
    }

    private function sendContact(
        Order $order,
        Incident $incident,
        MissingSerialAutomationAction $action,
    ): MissingSerialAutomationOrderResult {
        if ($action === MissingSerialAutomationAction::Request
            && $order->missing_serial_automation_status === MissingSerialAutomationStatus::Requested->value) {
            return new MissingSerialAutomationOrderResult(
                orderId: $order->id,
                action: $action,
                outcome: 'skipped',
                message: 'Initial request already sent.',
            );
        }

        if ($action === MissingSerialAutomationAction::Reminder
            && $order->missing_serial_automation_status === MissingSerialAutomationStatus::Reminded->value) {
            return new MissingSerialAutomationOrderResult(
                orderId: $order->id,
                action: $action,
                outcome: 'skipped',
                message: 'Reminder already sent.',
            );
        }

        $priorContactSkip = $this->skipInitialRequestIfPriorContactExists($order, $action);

        if ($priorContactSkip !== null) {
            return $priorContactSkip;
        }

        $notificationType = $this->notificationTypeForAction($action);
        $channels = $this->channelAvailabilityForAction($order, $action);
        $channelBlockReason = $this->channelAvailabilityService->unavailableReason($channels);

        if ($channelBlockReason !== null) {
            return new MissingSerialAutomationOrderResult(
                orderId: $order->id,
                action: $action,
                outcome: 'skipped',
                message: $channelBlockReason,
            );
        }

        $dispatchResult = $this->notificationDispatcher->send(
            $notificationType,
            new NotificationMessage(
                type: $notificationType,
                customer: $order,
                incident: $incident,
                template: $action === MissingSerialAutomationAction::Request
                    ? WhatsAppTemplate::RequestSerialNumber->value
                    : null,
                metadata: [
                    'source' => 'missing_serial_automation',
                    'trigger_source' => WhatsAppTemplateTriggerSource::Scheduler->value,
                    'automation_action' => $action->value,
                ],
            ),
        );

        $channelOutcomes = $this->summarizeChannelOutcomes($dispatchResult->results);
        $whatsappOutcome = $channelOutcomes[NotificationChannelType::WhatsApp->value] ?? 'skipped';
        $emailOutcome = $channelOutcomes[NotificationChannelType::Email->value] ?? 'skipped';

        $delivered = collect($dispatchResult->results)
            ->contains(fn ($result): bool => $result->countsTowardSuccess());

        if (! $delivered) {
            Log::warning('missing_serial.automation.contact_failed', [
                'order_id' => $order->order_id,
                'order_db_id' => $order->id,
                'action' => $action->value,
                'channels' => $channelOutcomes,
            ]);

            return new MissingSerialAutomationOrderResult(
                orderId: $order->id,
                action: $action,
                outcome: 'failed',
                message: $dispatchResult->message,
                channels: $channelOutcomes,
            );
        }

        $now = now();

        DB::transaction(function () use ($order, $incident, $action, $now, $channelOutcomes): void {
            $updates = [
                'missing_serial_last_contacted_at' => $now,
            ];

            if ($action === MissingSerialAutomationAction::Request) {
                $updates['missing_serial_automation_status'] = MissingSerialAutomationStatus::Requested->value;
                $updates['missing_serial_first_requested_at'] = $now;
            }

            if ($action === MissingSerialAutomationAction::Reminder) {
                $updates['missing_serial_automation_status'] = MissingSerialAutomationStatus::Reminded->value;
            }

            $order->update($updates);

            $incident = $incident->fresh(['activeWaitingState']);

            if ($this->waitingStateService->activeFor($incident) === null) {
                $this->waitingStateService->ensureSerialWaitingState(
                    $incident,
                    $this->automationIdentityService->systemUser(),
                );
                $incident = $incident->fresh(['activeWaitingState']);
            }

            if ($action === MissingSerialAutomationAction::Reminder) {
                $waitingState = $this->waitingStateService->activeFor($incident);

                if ($waitingState !== null) {
                    $this->customerWaitingLifecycleService->recordFollowupSent($waitingState, $now);
                }
            }

            $auditContext = [
                'channels' => $channelOutcomes,
            ];

            if ($action === MissingSerialAutomationAction::Request) {
                $this->auditService->recordRequestSent($order->fresh(), $incident, $auditContext);
            } else {
                $this->auditService->recordReminderSent($order->fresh(), $incident, $auditContext);
            }
        });

        return new MissingSerialAutomationOrderResult(
            orderId: $order->id,
            action: $action,
            outcome: 'sent',
            message: $dispatchResult->message,
            channels: [
                'whatsapp' => $whatsappOutcome,
                'email' => $emailOutcome,
            ],
        );
    }

    private function escalateOrder(Order $order, Incident $incident): MissingSerialAutomationOrderResult
    {
        $coordinator = $this->resolveCoordinatorAssignee($order);
        $now = now();

        DB::transaction(function () use ($order, $incident, $coordinator, $now): void {
            if ($coordinator !== null) {
                $incident->update([
                    'assigned_to_user_id' => $coordinator->id,
                    'high_priority' => true,
                ]);
            }

            $order->update([
                'missing_serial_automation_status' => MissingSerialAutomationStatus::Escalated->value,
                'missing_serial_escalated_at' => $now,
                'missing_serial_last_contacted_at' => $now,
            ]);

            $this->auditService->recordEscalated(
                order: $order->fresh(),
                incident: $incident->fresh(),
                coordinatorUserId: $coordinator?->id,
            );
        });

        return new MissingSerialAutomationOrderResult(
            orderId: $order->id,
            action: MissingSerialAutomationAction::Escalate,
            outcome: 'sent',
            message: $coordinator !== null
                ? 'Escalated to customer coordinator.'
                : 'Escalated without coordinator assignment.',
        );
    }

    /**
     * @param  array<int, \App\Data\NotificationResult>  $results
     * @return array<string, string>
     */
    private function summarizeChannelOutcomes(array $results): array
    {
        $summary = [];

        foreach ($results as $result) {
            $summary[$result->channel->value] = match (true) {
                $result->isSkipped() => 'skipped',
                $result->success => 'sent',
                default => 'failed',
            };
        }

        return $summary;
    }

    private function resolveIncident(Order $order): ?Incident
    {
        return $order->activeIncident() ?? $order->latestIncident();
    }

    private function paymentReferenceAt(Order $order): ?Carbon
    {
        return $order->payment_date ?? $order->created_at;
    }

    private function hasRadiumBoxEnrichmentAttempted(Order $order): bool
    {
        if (! Order::supportsRadiumBoxSyncTracking()) {
            return false;
        }

        return $order->radiumbox_sync_attempts > 0
            || $order->radiumbox_sync_status !== RadiumBoxEnrichmentSyncStatus::NotSynced
            || $order->radiumbox_last_sync_at !== null;
    }

    private function isOrderBlocked(Order $order): bool
    {
        if ($order->status === OrderStatus::Closed) {
            return true;
        }

        return $order->refundRequests()
            ->where('status', RefundStatus::Approved->value)
            ->exists();
    }

    private function resolveCoordinatorAssignee(Order $order): ?User
    {
        $coordinators = User::query()
            ->where('is_active', true)
            ->role(RolePermissionSeeder::ROLE_CUSTOMER_COORDINATOR)
            ->orderBy('id')
            ->get();

        if ($coordinators->isEmpty()) {
            return null;
        }

        $index = $order->id % $coordinators->count();

        return $coordinators->get($index);
    }

    private function notificationTypeForAction(MissingSerialAutomationAction $action): NotificationType
    {
        return $action === MissingSerialAutomationAction::Reminder
            ? NotificationType::CustomerWaitingFollowup
            : NotificationType::RequestSerialNumber;
    }

    /**
     * @return array{whatsapp: array<string, mixed>, email: array<string, mixed>}
     */
    private function channelAvailabilityForAction(Order $order, MissingSerialAutomationAction $action): array
    {
        if ($action === MissingSerialAutomationAction::Reminder) {
            return [
                'whatsapp' => $this->channelAvailabilityService->assessWhatsApp(
                    $order,
                    WhatsAppTemplate::CustomerWaitingFollowup,
                ),
                'email' => $this->channelAvailabilityService->assessEmailForNotificationType(
                    $order,
                    NotificationType::CustomerWaitingFollowup,
                ),
            ];
        }

        return $this->channelAvailabilityService->forRequestSerialNumber($order);
    }

    private function firstDelayMinutes(): int
    {
        return max(1, (int) config('missing_serial.first_delay_minutes', 15));
    }

    private function reminderDelayHours(): int
    {
        return max(1, (int) config('missing_serial.reminder_delay_hours', 24));
    }

    private function escalationDelayHours(): int
    {
        return max(1, (int) config('missing_serial.escalation_delay_hours', 72));
    }

    private function skipInitialRequestIfPriorContactExists(
        Order $order,
        MissingSerialAutomationAction $action,
    ): ?MissingSerialAutomationOrderResult {
        if ($action !== MissingSerialAutomationAction::Request) {
            return null;
        }

        $existingDispatch = $this->findSuccessfulRequestSerialDispatch($order);

        if ($existingDispatch === null) {
            return null;
        }

        $this->syncRequestTrackingFromPriorContact($order, $existingDispatch);

        return new MissingSerialAutomationOrderResult(
            orderId: $order->id,
            action: $action,
            outcome: 'skipped',
            message: 'Initial request already sent.',
        );
    }

    private function findSuccessfulRequestSerialDispatch(Order $order): ?WhatsAppTemplateDispatch
    {
        return WhatsAppTemplateDispatch::query()
            ->where('order_id', $order->id)
            ->where('template_key', WhatsAppTemplate::RequestSerialNumber->value)
            ->where('status', WhatsAppTemplateDispatchStatus::Sent->value)
            ->orderBy('id')
            ->first();
    }

    private function syncRequestTrackingFromPriorContact(
        Order $order,
        WhatsAppTemplateDispatch $dispatch,
    ): void {
        $contactedAt = $dispatch->dispatched_at ?? $dispatch->created_at ?? now();

        $updates = [];

        if ($order->missing_serial_automation_status === null) {
            $updates['missing_serial_automation_status'] = MissingSerialAutomationStatus::Requested->value;
        }

        if ($order->missing_serial_first_requested_at === null) {
            $updates['missing_serial_first_requested_at'] = $contactedAt;
        }

        if ($order->missing_serial_last_contacted_at === null) {
            $updates['missing_serial_last_contacted_at'] = $contactedAt;
        }

        if ($updates !== []) {
            $order->update($updates);
        }

        $incident = $this->resolveIncident($order);

        if ($incident !== null) {
            $this->waitingStateService->ensureSerialWaitingState(
                $incident->fresh(['activeWaitingState']),
                $this->automationIdentityService->systemUser(),
            );
        }
    }
}
