<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Cwid;
use App\CentralWallet\Domain\Enums\AuditActorType;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use Illuminate\Support\Facades\DB;

final class CentralWalletService
{
    public function __construct(
        private readonly AuditEventRecorder $auditEvents,
    ) {}

    public function create(?string $correlationId = null): CentralWallet
    {
        return DB::transaction(function () use ($correlationId): CentralWallet {
            $cwid = Cwid::generate();

            $wallet = CentralWallet::query()->create([
                'id' => $cwid->value,
                'status' => 'active',
            ]);

            $this->auditEvents->record(
                eventType: 'wallet.created',
                centralWalletId: $wallet->id,
                actorType: AuditActorType::Service,
                actorId: 'central_wallet_api',
                correlationId: $correlationId,
                payload: ['status' => $wallet->status],
            );

            return $wallet;
        });
    }

    public function find(string $cwid): ?CentralWallet
    {
        Cwid::fromString($cwid);

        return CentralWallet::query()->find($cwid);
    }
}
