<?php

namespace App\Services\RadiumBox;

use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Enums\RadiumBoxSyncTrigger;
use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class RadiumBoxAutoSyncTriggerService
{
    public function __construct(
        private readonly RadiumBoxOrderEnrichmentService $enrichmentService,
        private readonly RadiumBoxOrderEnrichmentSyncStore $syncStore,
        private readonly RadiumBoxService $radiumBoxService,
        private readonly RadiumBoxEnrichmentRetryPolicy $retryPolicy,
    ) {}

    public function maybeDispatch(Order $order, RadiumBoxSyncTrigger $trigger): bool
    {
        if (! $this->shouldDispatch($order)) {
            return false;
        }

        $this->enrichmentService->dispatch($order->fresh());

        Log::info('RadiumBox auto sync triggered.', [
            'order_id' => $order->order_id,
            'order_db_id' => $order->id,
            'trigger' => $trigger->value,
            'previous_sync_status' => $this->syncStore->status($order->id)->value,
        ]);

        return true;
    }

    public function shouldDispatch(Order $order): bool
    {
        if (! config('radiumbox.enabled', true)
            || ! config('radiumbox.auto_sync.enabled', true)) {
            return false;
        }

        if ($order->isInquiryOrder() || ! filled($order->order_id)) {
            return false;
        }

        if (! $this->radiumBoxService->needsEnrichment($order)) {
            return false;
        }

        $status = $this->syncStore->status($order->id);

        if ($status === RadiumBoxEnrichmentSyncStatus::Pending) {
            return false;
        }

        if (! $this->hasCooldownElapsed($order, $status)) {
            return false;
        }

        return true;
    }

    private function hasCooldownElapsed(Order $order, RadiumBoxEnrichmentSyncStatus $status): bool
    {
        $lastAttemptAt = $this->syncStore->lastAttemptAt($order->id);
        $minIntervalMinutes = max(1, (int) config('radiumbox.auto_sync.min_interval_minutes', 30));

        if ($lastAttemptAt !== null) {
            $lastAttempt = Carbon::parse($lastAttemptAt, config('app.timezone'));
            $minutesSinceLastAttempt = $lastAttempt->diffInMinutes(now());

            if ($minutesSinceLastAttempt < $minIntervalMinutes) {
                return false;
            }
        }

        if ($status === RadiumBoxEnrichmentSyncStatus::Failed) {
            return $this->retryPolicy->hasRetryIntervalElapsed($order, $lastAttemptAt);
        }

        return true;
    }
}
