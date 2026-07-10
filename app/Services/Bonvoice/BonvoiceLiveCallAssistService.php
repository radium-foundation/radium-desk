<?php

namespace App\Services\Bonvoice;

use App\Models\BonvoiceCallAlert;
use App\Models\BonvoiceCallEvent;
use App\Models\User;
use App\Notifications\IncomingCallAssistNotification;
use App\Support\BonvoiceCallStatuses;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

class BonvoiceLiveCallAssistService
{
    public function __construct(
        private readonly BonvoiceAgentResolver $agentResolver,
        private readonly BonvoiceInboundCustomerResolver $customerResolver,
    ) {}

    public function maybeNotify(BonvoiceCallEvent $event): ?BonvoiceCallAlert
    {
        if (! $this->isInbound($event)) {
            return null;
        }

        if (! BonvoiceCallStatuses::isRingingStatus($event->status)) {
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
}
