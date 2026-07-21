<?php

namespace App\Services\Bonvoice;

use App\Enums\BonvoiceCallAlertType;
use App\Enums\RadiumBoxSyncTrigger;
use App\Models\BonvoiceCallAlert;
use App\Models\BonvoiceCallEvent;
use App\Models\Order;
use App\Models\User;
use App\Notifications\IncomingCallAssistNotification;
use App\Services\Alerts\IncomingCallTelegramMessageBuilder;
use App\Services\Alerts\OperatorAlertCatalog;
use App\Services\Alerts\OperatorAlertDispatcher;
use App\Services\DashboardBroadcastService;
use App\Services\HybridRealtime\HybridRealtimeNotificationBroadcaster;
use App\Services\RadiumBox\RadiumBoxAutoSyncTriggerService;
use App\Support\Bonvoice\BonvoiceIncomingCallInteractionBuilder;
use App\Support\BonvoiceCallStatuses;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

class BonvoiceLiveCallAssistService
{
    public function __construct(
        private readonly BonvoiceAgentResolver $agentResolver,
        private readonly BonvoiceInboundCustomerResolver $customerResolver,
        private readonly RadiumBoxAutoSyncTriggerService $radiumBoxAutoSyncTriggerService,
        private readonly DashboardBroadcastService $dashboardBroadcastService,
        private readonly OperatorAlertDispatcher $operatorAlertDispatcher,
        private readonly OperatorAlertCatalog $operatorAlertCatalog,
        private readonly IncomingCallTelegramMessageBuilder $incomingCallTelegramMessageBuilder,
        private readonly HybridRealtimeNotificationBroadcaster $hybridRealtimeBroadcaster,
    ) {}

    public function maybeNotify(BonvoiceCallEvent $event): ?BonvoiceCallAlert
    {
        if (! $this->isInbound($event)) {
            return null;
        }

        if (! BonvoiceCallStatuses::isLiveAssistEligibleStatus($event->status)) {
            return null;
        }

        if (BonvoiceCallAlert::query()->where('call_id', $event->call_id)->exists()) {
            return null;
        }

        $agent = $this->agentResolver->resolveUserForCall($event);

        if ($agent === null) {
            return null;
        }

        $match = $this->customerResolver->resolve($event->customer_phone);

        try {
            $alert = BonvoiceCallAlert::query()->create([
                'bonvoice_call_event_id' => $event->id,
                'call_id' => $event->call_id,
                'user_id' => $agent->id,
                'alert_type' => $match['alert_type'],
                'customer_phone' => $match['customer_phone'],
                'order_id' => $match['order_id'],
                'incident_id' => $match['incident_id'],
                'notified_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if ($this->isDuplicateCallAlert($exception)) {
                return null;
            }

            throw $exception;
        }

        $alert->loadMissing(['order', 'incident']);

        $this->maybeTriggerRadiumBoxSync($match);

        $this->hybridRealtimeBroadcaster->broadcastIncomingCall($agent, $alert);
        $this->sendNotificationSafely($agent, $alert);

        return $alert;
    }

    public function maybeBroadcastAnsweredAutoOpen(BonvoiceCallEvent $event, ?string $previousStatus): void
    {
        if (! config('bonvoice.auto_open_customer360')) {
            return;
        }

        if (! BonvoiceCallStatuses::isInbound($event->direction)) {
            return;
        }

        if (! BonvoiceCallStatuses::transitionedToAnswered($previousStatus, $event->status)) {
            return;
        }

        if ($previousStatus === null) {
            return;
        }

        $alert = BonvoiceCallAlert::query()
            ->where('call_id', $event->call_id)
            ->with(['order', 'incident', 'user'])
            ->first();

        if ($alert === null) {
            if (config('app.debug')) {
                Log::debug('bonvoice.auto_open_customer360.skipped_no_alert', [
                    'call_id' => $event->call_id,
                ]);
            }

            return;
        }

        if ($alert->alert_type === BonvoiceCallAlertType::UnknownCaller) {
            if (config('app.debug')) {
                Log::debug('bonvoice.auto_open_customer360.skipped_unknown_customer', [
                    'call_id' => $event->call_id,
                ]);
            }

            return;
        }

        if ($alert->incident_id === null) {
            if (config('app.debug')) {
                Log::debug('bonvoice.auto_open_customer360.skipped_no_incident', [
                    'call_id' => $event->call_id,
                ]);
            }

            return;
        }

        $agent = $alert->user ?? $this->agentResolver->resolveUserForCall($event);

        if ($agent === null) {
            return;
        }

        $interaction = BonvoiceIncomingCallInteractionBuilder::fromAlert($alert, 'answered');

        $this->dashboardBroadcastService->incomingCallInteraction($agent, $interaction);

        if (config('app.debug')) {
            Log::debug('bonvoice.auto_open_customer360.broadcast', [
                'call_id' => $event->call_id,
                'incident_id' => $alert->incident_id,
                'user_id' => $agent->id,
            ]);
        }
    }

    private function sendNotificationSafely(User $agent, BonvoiceCallAlert $alert): void
    {
        try {
            if ($this->hybridRealtimeBroadcaster->operatorAlertsEnabled() || config('operator_alerts.enabled')) {
                $this->dispatchOperatorAlert($agent, $alert);

                return;
            }

            $agent->notify(new IncomingCallAssistNotification($alert));
        } catch (Throwable $exception) {
            Log::error('[BonVoice Live Call Assist] Notification failed', [
                'call_id' => $alert->call_id,
                'user_id' => $agent->id,
                'bonvoice_call_alert_id' => $alert->id,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
        }
    }

    private function dispatchOperatorAlert(User $agent, BonvoiceCallAlert $alert): void
    {
        $historyNotification = new IncomingCallAssistNotification($alert);
        $payload = $historyNotification->toArray($agent);

        $operatorAlert = $this->operatorAlertCatalog->make(
            eventType: OperatorAlertCatalog::EVENT_INCOMING_CALL,
            title: (string) ($payload['title'] ?? 'Incoming Call'),
            message: (string) ($payload['message'] ?? ''),
            actionUrl: (string) ($payload['url'] ?? route('dashboard')),
            entityType: 'call',
            entityId: $alert->call_id,
            deduplicationKey: 'ivr:call:'.$alert->call_id,
            interaction: is_array($payload['interaction'] ?? null) ? $payload['interaction'] : null,
        );

        $this->operatorAlertDispatcher->dispatch(
            alert: $operatorAlert,
            recipients: $agent,
            historyNotification: $historyNotification,
            persistHistory: true,
            deliverTelegram: true,
            telegramMessage: $this->incomingCallTelegramMessageBuilder->build(
                $alert,
                $operatorAlert->actionUrl,
            ),
        );
    }

    private function isDuplicateCallAlert(QueryException $exception): bool
    {
        $errorCode = (string) ($exception->errorInfo[1] ?? '');

        return in_array($errorCode, ['1062', '19', '2067', '1555'], true);
    }

    private function isInbound(BonvoiceCallEvent $event): bool
    {
        $direction = strtolower((string) $event->direction);

        return in_array($direction, ['inbound', 'in', 'incoming'], true);
    }

    /**
     * @param  array{
     *     alert_type: BonvoiceCallAlertType,
     *     customer_phone: ?string,
     *     order_id: ?int,
     *     order_label: ?string,
     *     incident_id: ?int,
     * }  $match
     */
    private function maybeTriggerRadiumBoxSync(array $match): void
    {
        $orderId = $match['order_id'] ?? null;

        if ($orderId === null) {
            return;
        }

        $order = Order::query()->find($orderId);

        if ($order === null) {
            return;
        }

        $this->radiumBoxAutoSyncTriggerService->maybeDispatch(
            $order,
            RadiumBoxSyncTrigger::BonvoiceLiveCallMatch,
        );
    }
}
