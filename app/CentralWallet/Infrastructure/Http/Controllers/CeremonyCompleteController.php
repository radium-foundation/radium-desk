<?php

namespace App\CentralWallet\Infrastructure\Http\Controllers;

use App\CentralWallet\Application\CeremonyCompleteService;
use App\CentralWallet\Application\IdempotencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CeremonyCompleteController
{
    public function __construct(
        private readonly CeremonyCompleteService $ceremonyComplete,
        private readonly IdempotencyService $idempotency,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:128'],
            'site_code' => ['required', 'string', 'max:64'],
            'local_user_id' => ['required', 'string', 'max:64'],
            'ceremony_verification_ref' => ['required', 'string', 'max:4096'],
            'verification_method' => ['nullable', 'string', 'max:64'],
        ]);

        $callerSite = trim((string) $request->header('X-Site-Code', ''));
        if ($callerSite === '') {
            $callerSite = (string) $request->attributes->get('central_wallet_caller_id');
        }

        if ($callerSite !== $validated['site_code']) {
            return response()->json(['error' => 'site_mismatch'], 403);
        }

        $callerId = (string) $request->attributes->get('central_wallet_caller_id');
        $correlationId = (string) $request->attributes->get('central_wallet_correlation_id');
        $requestHash = hash('sha256', json_encode([
            'site_code' => $validated['site_code'],
            'local_user_id' => $validated['local_user_id'],
            'ceremony_verification_ref' => $validated['ceremony_verification_ref'],
            'verification_method' => $validated['verification_method'] ?? null,
        ], JSON_THROW_ON_ERROR));

        $result = $this->idempotency->execute(
            $callerId,
            $validated['idempotency_key'],
            $requestHash,
            function () use ($validated, $correlationId): array {
                return $this->ceremonyComplete->complete(
                    siteCode: $validated['site_code'],
                    localUserId: $validated['local_user_id'],
                    ceremonyVerificationRef: $validated['ceremony_verification_ref'],
                    verificationMethod: $validated['verification_method'] ?? null,
                    correlationId: $correlationId !== '' ? $correlationId : null,
                );
            },
        );

        $body = $result['body'];
        if ($result['replay']) {
            $body['idempotent'] = true;
            unset($body['idempotent_replay']);
        } elseif (($body['status'] ?? null) === 'connected') {
            $body['idempotent'] = false;
        }

        return response()->json($body, $result['status']);
    }
}
