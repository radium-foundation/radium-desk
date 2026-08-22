<?php

namespace App\Services\Interakt;

use App\Data\NotificationMessage;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Blocks new WhatsApp dispatch creation for journeys that started before a
 * configured instant. Does not rewrite historical dispatch rows.
 */
class WhatsAppOutboundCutoff
{
    public const SKIPPED_STATUS = 'pre_cutoff';

    public const SKIPPED_MESSAGE = 'Skipped - WhatsApp outbound cutoff';

    public function shouldSkip(NotificationMessage $message): bool
    {
        $cutoffAt = $this->cutoffAt();

        if ($cutoffAt === null) {
            return false;
        }

        $journeyStartedAt = $this->journeyStartedAt($message);

        if ($journeyStartedAt === null) {
            return false;
        }

        return $journeyStartedAt->lt($cutoffAt);
    }

    public function cutoffAt(): ?Carbon
    {
        $raw = config('interakt.outbound_not_before');

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($raw), config('app.timezone'));
        } catch (Throwable $exception) {
            Log::warning('whatsapp.outbound_cutoff.invalid', [
                'exception' => $exception::class,
            ]);

            return null;
        }
    }

    public function journeyStartedAt(NotificationMessage $message): ?Carbon
    {
        $candidates = array_values(array_filter([
            $this->missingSerialJourneyStartedAt($message),
            $this->waitingStateJourneyStartedAt($message),
        ]));

        if ($candidates === []) {
            return null;
        }

        usort(
            $candidates,
            fn (Carbon $left, Carbon $right): int => $left->getTimestamp() <=> $right->getTimestamp(),
        );

        return $candidates[0];
    }

    private function missingSerialJourneyStartedAt(NotificationMessage $message): ?Carbon
    {
        $order = $this->resolveOrder($message);

        $startedAt = $order?->missing_serial_first_requested_at;

        return $startedAt instanceof Carbon ? $startedAt : null;
    }

    private function waitingStateJourneyStartedAt(NotificationMessage $message): ?Carbon
    {
        $waitingStateId = $message->metadata['waiting_state_id'] ?? null;

        if (is_numeric($waitingStateId) && (int) $waitingStateId > 0) {
            $waitingState = IncidentWaitingState::query()->find((int) $waitingStateId);
            $startedAt = $waitingState?->started_at;

            return $startedAt instanceof Carbon ? $startedAt : null;
        }

        $message->incident->loadMissing('activeWaitingState');
        $startedAt = $message->incident->activeWaitingState?->started_at;

        return $startedAt instanceof Carbon ? $startedAt : null;
    }

    private function resolveOrder(NotificationMessage $message): ?Order
    {
        $message->incident->loadMissing('order');

        if ($message->incident->order instanceof Order) {
            return $message->incident->order;
        }

        return $message->customer instanceof Order ? $message->customer : null;
    }
}
