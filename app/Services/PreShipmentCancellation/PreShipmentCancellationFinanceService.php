<?php

namespace App\Services\PreShipmentCancellation;

use App\Enums\PreShipmentCancellationStatus;
use App\Models\PreShipmentCancellationRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PreShipmentCancellationFinanceService
{
    public function __construct(
        private readonly PreShipmentCancellationFeature $feature,
    ) {}

    public function approve(User $actor, PreShipmentCancellationRequest $case): PreShipmentCancellationRequest
    {
        $this->feature->assertEnabled();

        return DB::transaction(function () use ($actor, $case): PreShipmentCancellationRequest {
            $locked = PreShipmentCancellationRequest::query()
                ->whereKey($case->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === PreShipmentCancellationStatus::FinanceApproved) {
                return $locked;
            }

            if ($locked->status !== PreShipmentCancellationStatus::FinanceReview) {
                throw ValidationException::withMessages([
                    'status' => 'Only cases in Finance review can be approved.',
                ]);
            }

            $locked->forceFill([
                'status' => PreShipmentCancellationStatus::FinanceApproved,
                'reviewed_by_user_id' => $actor->id,
            ])->save();

            return $locked->fresh() ?? $locked;
        });
    }

    public function reject(User $actor, PreShipmentCancellationRequest $case, string $reason): PreShipmentCancellationRequest
    {
        $this->feature->assertEnabled();

        return DB::transaction(function () use ($actor, $case, $reason): PreShipmentCancellationRequest {
            $locked = PreShipmentCancellationRequest::query()
                ->whereKey($case->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status->isTerminal()) {
                throw ValidationException::withMessages([
                    'status' => 'Terminal cases cannot be rejected.',
                ]);
            }

            $locked->forceFill([
                'status' => PreShipmentCancellationStatus::Rejected,
                'terminal_at' => now(),
                'reviewed_by_user_id' => $actor->id,
                'sub_status' => $reason,
            ])->save();

            return $locked->fresh() ?? $locked;
        });
    }
}
