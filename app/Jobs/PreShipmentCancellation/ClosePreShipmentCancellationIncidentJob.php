<?php

namespace App\Jobs\PreShipmentCancellation;

use App\Enums\PreShipmentCancellationServiceCaseSyncStatus;
use App\Infrastructure\Queue\QueueRouting;
use App\Models\PreShipmentCancellationRequest;
use App\Models\User;
use App\Services\PreShipmentCancellation\PreShipmentCancellationFeature;
use App\Services\PreShipmentCancellation\PreShipmentCancellationIncidentService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job identity: preship-cancel-close-incident (dispatched to the shared critical queue).
 */
class ClosePreShipmentCancellationIncidentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const IDENTITY = 'preship-cancel-close-incident';

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $preShipmentCancellationRequestId,
        public readonly ?int $actorUserId = null,
    ) {
        $this->onQueue(QueueRouting::critical());
    }

    public function uniqueId(): string
    {
        return 'psc-incident:'.$this->preShipmentCancellationRequestId;
    }

    public function handle(
        PreShipmentCancellationIncidentService $incidentService,
        PreShipmentCancellationFeature $feature,
    ): void {
        if (! $feature->enabled()) {
            Log::info('[preship-cancel-close-incident] Skipped; feature disabled.', [
                'pre_shipment_cancellation_request_id' => $this->preShipmentCancellationRequestId,
            ]);

            return;
        }

        $case = PreShipmentCancellationRequest::query()->find($this->preShipmentCancellationRequestId);
        if ($case === null) {
            Log::warning('[preship-cancel-close-incident] Case missing.', [
                'pre_shipment_cancellation_request_id' => $this->preShipmentCancellationRequestId,
            ]);

            return;
        }

        if ($case->service_case_sync_status === PreShipmentCancellationServiceCaseSyncStatus::Succeeded
            || $case->service_case_sync_status === PreShipmentCancellationServiceCaseSyncStatus::NotApplicable) {
            return;
        }

        $actorId = $this->actorUserId ?? $case->submitted_by_user_id;
        $actor = $actorId !== null ? User::query()->find($actorId) : null;
        if ($actor === null) {
            Log::error('[preship-cancel-close-incident] Actor missing; cannot retry incident close.', [
                'pre_shipment_cancellation_request_id' => $case->id,
                'actor_user_id' => $actorId,
            ]);

            return;
        }

        $incidentService->retryClose($case, $actor);

        $case->refresh();
        if ($case->service_case_sync_status === PreShipmentCancellationServiceCaseSyncStatus::Failed) {
            Log::warning('[preship-cancel-close-incident] Incident close still failed after attempt.', [
                'pre_shipment_cancellation_request_id' => $case->id,
                'attempt' => $this->attempts(),
                'status' => $case->status->value,
            ]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('[preship-cancel-close-incident] Exhausted retries.', [
            'pre_shipment_cancellation_request_id' => $this->preShipmentCancellationRequestId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
