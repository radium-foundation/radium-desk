<?php

namespace App\Services\Refunds;

use App\Enums\BusinessHoldType;
use App\Enums\IncidentStatus;
use App\Enums\RefundStatus;
use App\Models\RefundRequest;
use App\Services\AutomationIdentityService;
use App\Services\RefundCaseCloseService;
use Illuminate\Support\Facades\Log;
use Throwable;

class RefundCompletedOpenCaseRepairService
{
    public function __construct(
        private readonly RefundCaseCloseService $caseCloseService,
        private readonly AutomationIdentityService $automationIdentity,
    ) {}

    /**
     * Close linked open cases for completed refunds that still hold an active refund business hold.
     *
     * @return array{
     *     scanned: int,
     *     repaired: int,
     *     failed: int,
     *     dry_run: bool,
     *     samples: list<array<string, mixed>>,
     *     configuration_error: ?string,
     * }
     */
    public function repair(bool $dryRun = true): array
    {
        try {
            $actor = $this->automationIdentity->systemUser();
        } catch (Throwable $exception) {
            return [
                'scanned' => 0,
                'repaired' => 0,
                'failed' => 0,
                'dry_run' => $dryRun,
                'samples' => [],
                'configuration_error' => 'Unable to resolve system actor: '.$exception->getMessage(),
            ];
        }

        $refunds = RefundRequest::query()
            ->where('status', RefundStatus::Completed)
            ->whereNotNull('incident_id')
            ->whereHas('incident', function ($query): void {
                $query->where('status', '!=', IncidentStatus::Closed->value)
                    ->whereHas('activeBusinessHold', function ($holdQuery): void {
                        $holdQuery->where('hold_type', BusinessHoldType::Refund->value);
                    });
            })
            ->with(['incident.activeBusinessHold', 'order'])
            ->orderBy('id')
            ->get();

        $repaired = 0;
        $failed = 0;
        $samples = [];

        foreach ($refunds as $refund) {
            $sample = [
                'refund_id' => $refund->id,
                'reference_no' => $refund->reference_no,
                'incident_id' => $refund->incident_id,
                'case' => $refund->incident?->reference_no,
                'case_status' => $refund->incident?->status?->value,
                'order' => $refund->order?->order_id,
                'hold_id' => $refund->incident?->activeBusinessHold?->id,
            ];

            if ($dryRun) {
                $sample['action'] = 'would_close';
                $samples[] = $sample;
                $repaired++;

                continue;
            }

            try {
                $this->caseCloseService->closeLinkedCase($refund, $actor);

                $refund = $refund->fresh(['incident.activeBusinessHold']);
                $incident = $refund?->incident;
                $closed = $refund !== null
                    && $refund->status === RefundStatus::Closed
                    && $incident !== null
                    && $incident->status === IncidentStatus::Closed
                    && $incident->activeBusinessHold === null;

                if ($closed) {
                    $repaired++;
                    $sample['action'] = 'closed';
                } else {
                    $failed++;
                    $sample['action'] = 'failed';
                    $sample['error'] = 'closeLinkedCase finished without closing case/refund or clearing hold.';
                    $sample['refund_status'] = $refund->status?->value;
                    $sample['case_status_after'] = $incident?->status?->value;
                }

                $samples[] = $sample;
            } catch (Throwable $exception) {
                $failed++;
                $sample['action'] = 'failed';
                $sample['error'] = $exception->getMessage();
                $samples[] = $sample;

                Log::error('[Refunds] Completed-open-case repair failed.', [
                    'refund_id' => $refund->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return [
            'scanned' => $refunds->count(),
            'repaired' => $repaired,
            'failed' => $failed,
            'dry_run' => $dryRun,
            'samples' => $samples,
            'configuration_error' => null,
        ];
    }
}
