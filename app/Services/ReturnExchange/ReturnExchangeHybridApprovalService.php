<?php

namespace App\Services\ReturnExchange;

use App\Enums\ReturnExchangeHybridApprovalDecision;
use App\Enums\ReturnExchangeHybridApprovalType;
use App\Models\ReturnExchangeCase;
use App\Models\ReturnExchangeCaseHybridApproval;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReturnExchangeHybridApprovalService
{
    /**
     * @param  array{decision: string, reason?: string|null, idempotency_key?: string|null}  $payload
     */
    public function record(
        User $actor,
        ReturnExchangeCase $case,
        ReturnExchangeHybridApprovalType $type,
        array $payload,
    ): ReturnExchangeCaseHybridApproval {
        $decision = ReturnExchangeHybridApprovalDecision::from((string) $payload['decision']);
        $reason = isset($payload['reason']) ? trim((string) $payload['reason']) : null;
        $idempotencyKey = isset($payload['idempotency_key']) && $payload['idempotency_key'] !== ''
            ? (string) $payload['idempotency_key']
            : null;

        if ($decision !== ReturnExchangeHybridApprovalDecision::Pending && ($reason === null || $reason === '')) {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required when recording an approval decision.',
            ]);
        }

        return DB::transaction(function () use ($actor, $case, $type, $decision, $reason, $idempotencyKey): ReturnExchangeCaseHybridApproval {
            $approval = ReturnExchangeCaseHybridApproval::query()
                ->where('return_exchange_case_id', $case->id)
                ->where('approval_type', $type->value)
                ->lockForUpdate()
                ->first();

            if ($approval === null) {
                $approval = ReturnExchangeCaseHybridApproval::query()->create([
                    'return_exchange_case_id' => $case->id,
                    'approval_type' => $type,
                    'decision' => ReturnExchangeHybridApprovalDecision::Pending,
                ]);
            }

            if ($idempotencyKey !== null && $approval->idempotency_key === $idempotencyKey) {
                return $approval->fresh(['decider']);
            }

            $approval->update([
                'decision' => $decision,
                'reason' => $reason,
                'decided_by' => $decision === ReturnExchangeHybridApprovalDecision::Pending ? null : $actor->id,
                'decided_at' => $decision === ReturnExchangeHybridApprovalDecision::Pending ? null : now(),
                'idempotency_key' => $idempotencyKey ?? $approval->idempotency_key,
            ]);

            return $approval->fresh(['decider']);
        });
    }

    public function ensurePendingRows(ReturnExchangeCase $case): void
    {
        foreach (ReturnExchangeHybridApprovalType::cases() as $type) {
            ReturnExchangeCaseHybridApproval::query()->firstOrCreate(
                [
                    'return_exchange_case_id' => $case->id,
                    'approval_type' => $type,
                ],
                [
                    'decision' => ReturnExchangeHybridApprovalDecision::Pending,
                ],
            );
        }
    }
}
