<?php

namespace App\CentralWallet\Infrastructure\Http\Controllers;

use App\CentralWallet\Application\CentralWalletService;
use App\CentralWallet\Application\IdempotencyService;
use App\CentralWallet\Application\IntegrationSourceSystemResolver;
use App\CentralWallet\Application\LedgerService;
use App\CentralWallet\Domain\Cwid;
use App\CentralWallet\Domain\Enums\LedgerEntryType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WalletController
{
    public function __construct(
        private readonly CentralWalletService $wallets,
        private readonly LedgerService $ledger,
        private readonly IdempotencyService $idempotency,
        private readonly IntegrationSourceSystemResolver $sourceSystemResolver,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $idempotencyKey = (string) $request->input('idempotency_key', '');
        if ($idempotencyKey === '') {
            return response()->json(['error' => 'idempotency_key_required'], 422);
        }

        $callerId = (string) $request->attributes->get('central_wallet_caller_id');
        $correlationId = (string) $request->attributes->get('central_wallet_correlation_id');
        $requestHash = hash('sha256', 'create_wallet');

        $result = $this->idempotency->execute($callerId, $idempotencyKey, $requestHash, function () use ($correlationId): array {
            $wallet = $this->wallets->create($correlationId);

            return [
                'status' => 201,
                'body' => [
                    'central_wallet_id' => $wallet->id,
                    'status' => $wallet->status,
                ],
                'resource_type' => 'central_wallet',
                'resource_id' => $wallet->id,
            ];
        });

        return response()->json($result['body'], $result['status']);
    }

    public function show(string $cwid): JsonResponse
    {
        try {
            Cwid::fromString($cwid);
        } catch (\InvalidArgumentException) {
            return response()->json(['error' => 'invalid_cwid'], 422);
        }

        $wallet = $this->wallets->find($cwid);
        if ($wallet === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json([
            'central_wallet_id' => $wallet->id,
            'status' => $wallet->status,
        ]);
    }

    public function balance(string $cwid): JsonResponse
    {
        try {
            Cwid::fromString($cwid);
        } catch (\InvalidArgumentException) {
            return response()->json(['error' => 'invalid_cwid'], 422);
        }

        $wallet = $this->wallets->find($cwid);
        if ($wallet === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json([
            'central_wallet_id' => $wallet->id,
            'ledger_balance' => $this->ledger->ledgerBalance($wallet->id),
            'reserved_balance' => $this->ledger->reservedBalance($wallet->id),
            'available_balance' => $this->ledger->availableBalance($wallet->id),
            'currency' => config('central_wallet.currency', 'INR'),
        ]);
    }

    public function appendLedgerEntry(Request $request, string $cwid): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:128'],
            'entry_type' => ['required', 'in:credit,debit,reversal,adjustment'],
            'amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'source_system' => ['nullable', 'string', 'max:64'],
            'source_reference' => ['nullable', 'string', 'max:191'],
            'business_reference' => ['nullable', 'string', 'max:191'],
            'original_ledger_entry_id' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            Cwid::fromString($cwid);
        } catch (\InvalidArgumentException) {
            return response()->json(['error' => 'invalid_cwid'], 422);
        }

        $callerId = (string) $request->attributes->get('central_wallet_caller_id');
        $correlationId = (string) $request->attributes->get('central_wallet_correlation_id');

        try {
            $sourceSystem = $this->sourceSystemResolver->resolveAuthoritative(
                $callerId,
                $validated['source_system'] ?? null,
            );
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'error' => 'source_system_mismatch',
                'message' => $exception->getMessage(),
            ], 422);
        }

        $validated['source_system'] = $sourceSystem;
        $requestHash = hash('sha256', json_encode($validated, JSON_THROW_ON_ERROR));

        $result = $this->idempotency->execute(
            $callerId,
            $validated['idempotency_key'],
            $requestHash,
            function () use ($cwid, $validated, $correlationId, $sourceSystem): array {
                try {
                    $entry = $this->ledger->appendEntry(
                        centralWalletId: $cwid,
                        entryType: LedgerEntryType::from($validated['entry_type']),
                        amount: $validated['amount'],
                        sourceSystem: $sourceSystem,
                        correlationId: $correlationId,
                        sourceReference: $validated['source_reference'] ?? null,
                        businessReference: $validated['business_reference'] ?? null,
                        originalLedgerEntryId: $validated['original_ledger_entry_id'] ?? null,
                    );
                } catch (\InvalidArgumentException $exception) {
                    return [
                        'status' => 422,
                        'body' => ['error' => 'ledger_append_failed', 'message' => $exception->getMessage()],
                    ];
                }

                return [
                    'status' => 201,
                    'body' => [
                        'ledger_entry_id' => $entry->id,
                        'central_wallet_id' => $entry->central_wallet_id,
                        'entry_type' => $entry->entry_type->value,
                        'amount' => (string) $entry->amount,
                        'currency' => $entry->currency,
                        'source_reference' => $entry->source_reference,
                        'business_reference' => $entry->business_reference,
                        'correlation_id' => $entry->correlation_id,
                        'reservation_id' => $entry->reservation_id,
                        'original_ledger_entry_id' => $entry->original_ledger_entry_id,
                    ],
                    'resource_type' => 'ledger_entry',
                    'resource_id' => (string) $entry->id,
                ];
            },
        );

        return response()->json($result['body'], $result['status']);
    }
}
