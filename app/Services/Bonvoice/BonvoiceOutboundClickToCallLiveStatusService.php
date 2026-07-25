<?php

namespace App\Services\Bonvoice;

use App\Data\Bonvoice\BonvoiceClickToCallContext;
use App\Enums\OutboundClickToCallLifecycleStatus;
use App\Events\Dashboard\OutboundClickToCallStatusUpdated;
use App\Models\BonvoiceCallEvent;
use App\Models\User;
use App\Support\Bonvoice\OutboundClickToCallLifecycleNormalizer;
use App\Support\BonvoiceCallStatuses;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BonvoiceOutboundClickToCallLiveStatusService
{
    private const CACHE_PREFIX = 'bonvoice:click_to_call:lifecycle:';

    private const CACHE_TTL_SECONDS = 3600;

    public function broadcastStarted(User $agent, string $eventId, BonvoiceClickToCallContext $context): void
    {
        $this->broadcastLifecycle(
            recipient: $agent,
            eventId: $eventId,
            lifecycleStatus: OutboundClickToCallLifecycleStatus::Calling,
            callId: null,
            incidentId: $context->incidentId(),
            orderId: $context->orderId(),
        );
    }

    public function maybeBroadcast(BonvoiceCallEvent $event, ?string $previousStatus): void
    {
        if (! $this->isClickToCallOutbound($event)) {
            return;
        }

        $callbackParams = is_array($event->callback_params) ? $event->callback_params : [];
        $userId = (int) ($callbackParams['user_id'] ?? 0);
        $eventId = (string) ($callbackParams['event_id'] ?? $event->event_id ?? '');

        if ($userId <= 0 || $eventId === '') {
            return;
        }

        $lifecycleStatus = OutboundClickToCallLifecycleNormalizer::normalize(
            status: $event->status,
            agentStatus: $event->agent_status,
            leg: $event->leg,
        );

        if ($lifecycleStatus === null) {
            return;
        }

        $previousLifecycle = OutboundClickToCallLifecycleNormalizer::normalize(
            status: $previousStatus,
            agentStatus: null,
            leg: $event->leg,
        );

        if ($previousLifecycle === $lifecycleStatus) {
            return;
        }

        $recipient = User::query()->find($userId);

        if ($recipient === null) {
            return;
        }

        $this->broadcastLifecycle(
            recipient: $recipient,
            eventId: $eventId,
            lifecycleStatus: $lifecycleStatus,
            callId: $event->call_id,
            incidentId: isset($callbackParams['incident_id']) ? (int) $callbackParams['incident_id'] : null,
            orderId: isset($callbackParams['order_id']) ? (int) $callbackParams['order_id'] : null,
        );
    }

    private function broadcastLifecycle(
        User $recipient,
        string $eventId,
        OutboundClickToCallLifecycleStatus $lifecycleStatus,
        ?string $callId,
        ?int $incidentId,
        ?int $orderId,
    ): void {
        if (! $this->shouldBroadcast($eventId, $lifecycleStatus)) {
            return;
        }

        $payload = [
            'event_id' => $eventId,
            'call_id' => $callId,
            'lifecycle_status' => $lifecycleStatus->value,
            'incident_id' => $incidentId,
            'order_id' => $orderId,
            'terminal' => $lifecycleStatus->isTerminal(),
            'updated_at' => now()->toIso8601String(),
        ];

        DB::afterCommit(function () use ($recipient, $payload, $eventId, $lifecycleStatus): void {
            $freshRecipient = User::query()->find($recipient->id);

            if ($freshRecipient === null) {
                return;
            }

            broadcast(new OutboundClickToCallStatusUpdated($freshRecipient, $payload));

            Log::info('[BonVoice Click-to-Call] Lifecycle status broadcast', [
                'event_id' => $eventId,
                'lifecycle_status' => $lifecycleStatus->value,
                'user_id' => $freshRecipient->id,
                'call_id' => $payload['call_id'],
            ]);
        });
    }

    private function shouldBroadcast(string $eventId, OutboundClickToCallLifecycleStatus $lifecycleStatus): bool
    {
        $cacheKey = self::CACHE_PREFIX.$eventId;
        $lastStatus = Cache::get($cacheKey);

        if ($lastStatus === $lifecycleStatus->value) {
            return false;
        }

        Cache::put($cacheKey, $lifecycleStatus->value, self::CACHE_TTL_SECONDS);

        return true;
    }

    private function isClickToCallOutbound(BonvoiceCallEvent $event): bool
    {
        if (! BonvoiceCallStatuses::isOutbound($event->direction)) {
            return false;
        }

        $callbackParams = is_array($event->callback_params) ? $event->callback_params : [];

        return ($callbackParams['source'] ?? null) === 'radium_desk'
            && filled($callbackParams['user_id'] ?? null)
            && filled($callbackParams['event_id'] ?? $event->event_id);
    }
}
