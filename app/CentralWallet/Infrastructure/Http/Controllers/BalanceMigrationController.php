<?php

namespace App\CentralWallet\Infrastructure\Http\Controllers;

use App\CentralWallet\Application\BalanceMigrationCutoverService;
use App\CentralWallet\Application\IdempotencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BalanceMigrationController
{
    public function __construct(
        private readonly BalanceMigrationCutoverService $cutover,
        private readonly IdempotencyService $idempotency,
    ) {}

    public function execute(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'migration_batch_id' => ['nullable', 'string', 'max:64'],
            'owner_approval_ref' => ['nullable', 'string', 'max:191'],
            'source_site_code' => ['required', 'string', 'max:64'],
            'source_local_user_id' => ['required', 'string', 'max:64'],
            'source_users_wallet_id' => ['required', 'integer', 'min:1'],
            'source_order_reference' => ['required', 'string', 'max:191'],
            'source_business_reference' => ['required', 'string', 'max:191'],
            'source_amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'source_currency' => ['nullable', 'string', 'size:3'],
            'source_created_at' => ['nullable', 'date'],
            'destination_central_wallet_id' => ['required', 'uuid'],
        ]);

        $callerId = (string) $request->attributes->get('central_wallet_caller_id');
        $correlationId = (string) $request->attributes->get('central_wallet_correlation_id');
        $actorId = 'migration:'.$callerId;

        $idempotencyKey = trim((string) ($validated['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') {
            $idempotencyKey = 'migration-exec:'.hash('sha256', json_encode($validated, JSON_THROW_ON_ERROR));
        }

        $requestHash = hash('sha256', json_encode($validated, JSON_THROW_ON_ERROR));

        $result = $this->idempotency->execute(
            $callerId,
            $idempotencyKey,
            $requestHash,
            fn (): array => $this->cutover->execute($validated, $callerId, $correlationId, $actorId),
        );

        return response()->json($result['body'], $result['status']);
    }
}
