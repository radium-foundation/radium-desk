<?php

namespace App\CentralWallet\Infrastructure\Http\Controllers;

use App\CentralWallet\Application\IdempotencyService;
use App\CentralWallet\Application\ReservationService;
use App\CentralWallet\Application\TrustedFinancialAuthorizationGate;
use App\CentralWallet\Domain\Cwid;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletReservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class ReservationController
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly IdempotencyService $idempotency,
        private readonly TrustedFinancialAuthorizationGate $financialAuthorization,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:128'],
            'central_wallet_id' => ['required', 'uuid'],
            'amount' => ['required', 'regex:/^\d+(\.\d{1,2})?$/'],
            'business_reference' => ['required', 'string', 'max:191'],
            'source_reference' => ['nullable', 'string', 'max:191'],
            'metadata' => ['nullable', 'array'],
            'local_user_id' => ['nullable', 'string', 'max:64'],
        ]);

        $siteCode = trim((string) $request->header('X-Site-Code', ''));
        if ($siteCode === '') {
            $siteCode = $callerId = (string) $request->attributes->get('central_wallet_caller_id');
        } else {
            $callerId = (string) $request->attributes->get('central_wallet_caller_id');
        }

        $authorizationError = $this->financialAuthorization->authorizeReservation(
            siteCode: $siteCode,
            centralWalletId: $validated['central_wallet_id'],
            localUserId: $validated['local_user_id'] ?? null,
        );
        if ($authorizationError !== null) {
            return response()->json(['error' => $authorizationError], 403);
        }

        try {
            Cwid::fromString($validated['central_wallet_id']);
        } catch (InvalidArgumentException) {
            return response()->json(['error' => 'invalid_cwid'], 422);
        }

        $callerId = (string) $request->attributes->get('central_wallet_caller_id');
        $correlationId = (string) $request->attributes->get('central_wallet_correlation_id');
        $requestHash = hash('sha256', json_encode($validated, JSON_THROW_ON_ERROR));

        $result = $this->idempotency->execute(
            $callerId,
            $validated['idempotency_key'],
            $requestHash,
            function () use ($validated, $callerId, $correlationId): array {
                try {
                    $reservation = $this->reservations->create(
                        centralWalletId: $validated['central_wallet_id'],
                        callerId: $callerId,
                        amount: $validated['amount'],
                        businessReference: $validated['business_reference'],
                        correlationId: $correlationId,
                        sourceReference: $validated['source_reference'] ?? null,
                        metadata: $validated['metadata'] ?? [],
                    );
                } catch (InvalidArgumentException $exception) {
                    $error = str_contains($exception->getMessage(), 'Insufficient')
                        ? 'insufficient_balance'
                        : 'reservation_create_failed';

                    return [
                        'status' => 422,
                        'body' => ['error' => $error, 'message' => $exception->getMessage()],
                    ];
                }

                return [
                    'status' => 201,
                    'body' => $this->reservationBody($reservation),
                    'resource_type' => 'wallet_reservation',
                    'resource_id' => $reservation->id,
                ];
            },
        );

        return response()->json($result['body'], $result['status']);
    }

    public function show(Request $request, string $reservationId): JsonResponse
    {
        $reservation = CentralWalletReservation::query()->find($reservationId);
        if ($reservation === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $callerId = (string) $request->attributes->get('central_wallet_caller_id');
        if ($reservation->caller_id !== $callerId) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        return response()->json($this->reservationBody($reservation));
    }

    public function commit(Request $request, string $reservationId): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:128'],
        ]);

        $callerId = (string) $request->attributes->get('central_wallet_caller_id');
        $correlationId = (string) $request->attributes->get('central_wallet_correlation_id');
        $requestHash = hash('sha256', json_encode([
            'action' => 'commit',
            'reservation_id' => $reservationId,
        ], JSON_THROW_ON_ERROR));

        $result = $this->idempotency->execute(
            $callerId.':commit',
            $validated['idempotency_key'],
            $requestHash,
            function () use ($reservationId, $callerId, $correlationId): array {
                try {
                    $commit = $this->reservations->commit($reservationId, $callerId, $correlationId);
                } catch (InvalidArgumentException $exception) {
                    return [
                        'status' => 422,
                        'body' => ['error' => 'reservation_commit_failed', 'message' => $exception->getMessage()],
                    ];
                }

                $reservation = $commit['reservation'];
                $entry = $commit['ledger_entry'];

                return [
                    'status' => 200,
                    'body' => array_merge($this->reservationBody($reservation), [
                        'ledger_entry_id' => $entry->id,
                        'ledger_entry_type' => $entry->entry_type->value,
                        'ledger_amount' => (string) $entry->amount,
                    ]),
                    'resource_type' => 'wallet_reservation_commit',
                    'resource_id' => $reservation->id,
                ];
            },
        );

        return response()->json($result['body'], $result['status']);
    }

    public function release(Request $request, string $reservationId): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:128'],
        ]);

        $callerId = (string) $request->attributes->get('central_wallet_caller_id');
        $correlationId = (string) $request->attributes->get('central_wallet_correlation_id');
        $requestHash = hash('sha256', json_encode([
            'action' => 'release',
            'reservation_id' => $reservationId,
        ], JSON_THROW_ON_ERROR));

        $result = $this->idempotency->execute(
            $callerId.':release',
            $validated['idempotency_key'],
            $requestHash,
            function () use ($reservationId, $callerId, $correlationId): array {
                try {
                    $reservation = $this->reservations->release($reservationId, $callerId, $correlationId);
                } catch (InvalidArgumentException $exception) {
                    return [
                        'status' => 422,
                        'body' => ['error' => 'reservation_release_failed', 'message' => $exception->getMessage()],
                    ];
                }

                return [
                    'status' => 200,
                    'body' => $this->reservationBody($reservation),
                    'resource_type' => 'wallet_reservation_release',
                    'resource_id' => $reservation->id,
                ];
            },
        );

        return response()->json($result['body'], $result['status']);
    }

    /**
     * @return array<string, mixed>
     */
    private function reservationBody(CentralWalletReservation $reservation): array
    {
        return [
            'reservation_id' => $reservation->id,
            'central_wallet_id' => $reservation->central_wallet_id,
            'state' => $reservation->state->value,
            'amount' => (string) $reservation->amount,
            'currency' => $reservation->currency,
            'business_reference' => $reservation->business_reference,
            'source_reference' => $reservation->source_reference,
            'correlation_id' => $reservation->correlation_id,
            'expires_at' => $reservation->expires_at?->toIso8601String(),
            'committed_at' => $reservation->committed_at?->toIso8601String(),
            'released_at' => $reservation->released_at?->toIso8601String(),
            'expired_at' => $reservation->expired_at?->toIso8601String(),
            'ledger_entry_id' => $reservation->ledger_entry_id,
        ];
    }
}
