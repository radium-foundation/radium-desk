<?php

namespace App\Services\Bonvoice;

use App\Enums\RadiumBoxSyncTrigger;
use App\Models\BonvoiceCallAlert;
use App\Models\BonvoiceCallEvent;
use App\Models\Order;
use App\Models\User;
use App\Notifications\IncomingCallAssistNotification;
use App\Services\RadiumBox\RadiumBoxAutoSyncTriggerService;
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

        $this->sendNotificationSafely($agent, $alert);

        return $alert;
    }

    private function sendNotificationSafely(User $agent, BonvoiceCallAlert $alert): void
    {
        try {
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
     *     alert_type: \App\Enums\BonvoiceCallAlertType,
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
