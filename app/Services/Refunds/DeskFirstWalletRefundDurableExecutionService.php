<?php

namespace App\Services\Refunds;

use App\CentralWallet\Application\CentralWalletRefundLedgerCreditService;
use App\Models\RefundRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Desk-first wallet refund durable steps: commit CW credit under refund row lock, then spoke audit (HTTP).
 */
final class DeskFirstWalletRefundDurableExecutionService
{
    public function __construct(
        private readonly CentralWalletRefundLedgerCreditService $deskCredits,
    ) {}

    /**
     * @return array{
     *     ledger_entry_id: int,
     *     central_wallet_id: string,
     *     wallet_reference: string,
     *     available_balance: string,
     *     idempotent_replay: bool,
     *     correlation_id: string,
     * }
     */
    public function ensureDeskCreditCommitted(RefundRequest $refund, User $actor): array
    {
        $correlationId = (string) Str::uuid();

        return DB::transaction(function () use ($refund, $correlationId): array {
            RefundRequest::query()->lockForUpdate()->findOrFail($refund->id);

            try {
                $deskCredit = $this->deskCredits->creditForRefund($refund, $correlationId);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'refund' => 'Central Wallet credit could not be posted: '.$exception->getMessage(),
                ]);
            }

            return [
                ...$deskCredit,
                'correlation_id' => $correlationId,
            ];
        });
    }
}
