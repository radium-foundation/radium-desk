<?php

namespace App\CentralWallet\Infrastructure\Http\Controllers;

use App\CentralWallet\Application\AccountLinkService;
use App\CentralWallet\Application\IdempotencyService;
use App\CentralWallet\Domain\Cwid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class AccountLinkController
{
    public function __construct(
        private readonly AccountLinkService $accountLinks,
        private readonly IdempotencyService $idempotency,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:128'],
            'central_wallet_id' => ['required', 'string', 'max:36'],
            'site_code' => ['required', 'string', 'max:64'],
            'local_user_id' => ['required', 'string', 'max:64'],
            'verification_method' => ['nullable', 'string', 'max:64'],
            'created_by' => ['required', 'string', 'max:128'],
        ]);

        try {
            Cwid::fromString($validated['central_wallet_id']);
        } catch (\InvalidArgumentException) {
            return response()->json(['error' => 'invalid_cwid'], 422);
        }

        $callerId = (string) $request->attributes->get('central_wallet_caller_id');
        $correlationId = (string) $request->attributes->get('central_wallet_correlation_id');
        $requestHash = hash('sha256', json_encode($validated, JSON_THROW_ON_ERROR));

        $result = $this->idempotency->execute(
            $callerId,
            $validated['idempotency_key'],
            $requestHash,
            function () use ($validated, $correlationId): array {
                try {
                    $link = $this->accountLinks->createPendingLink(
                        centralWalletId: $validated['central_wallet_id'],
                        siteCode: $validated['site_code'],
                        localUserId: $validated['local_user_id'],
                        createdBy: $validated['created_by'],
                        verificationMethod: $validated['verification_method'] ?? null,
                        correlationId: $correlationId,
                    );
                } catch (RuntimeException $exception) {
                    return [
                        'status' => 409,
                        'body' => ['error' => 'link_conflict', 'message' => $exception->getMessage()],
                    ];
                }

                return [
                    'status' => 201,
                    'body' => [
                        'link_id' => $link->id,
                        'central_wallet_id' => $link->central_wallet_id,
                        'site_code' => $link->site_code,
                        'local_user_id' => $link->local_user_id,
                        'status' => $link->status->value,
                    ],
                    'resource_type' => 'account_link',
                    'resource_id' => (string) $link->id,
                ];
            },
        );

        return response()->json($result['body'], $result['status']);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site_code' => ['required', 'string', 'max:64'],
            'local_user_id' => ['required', 'string', 'max:64'],
        ]);

        $link = $this->accountLinks->findActiveBySiteUser(
            $validated['site_code'],
            $validated['local_user_id'],
        );

        if ($link === null) {
            return response()->json(['link' => null]);
        }

        return response()->json([
            'link' => [
                'link_id' => $link->id,
                'central_wallet_id' => $link->central_wallet_id,
                'site_code' => $link->site_code,
                'local_user_id' => $link->local_user_id,
                'status' => $link->status->value,
                'verification_method' => $link->verification_method,
                'linked_at' => $link->linked_at?->toIso8601String(),
            ],
        ]);
    }
}
