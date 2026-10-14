<?php

namespace App\CentralWallet\Infrastructure\Http\Controllers;

use App\CentralWallet\Application\CentralWalletCustomerIdentityResolveService;
use App\CentralWallet\Application\IdempotencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CustomerIdentityResolveController
{
    public function __construct(
        private readonly CentralWalletCustomerIdentityResolveService $identityResolve,
        private readonly IdempotencyService $idempotency,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:128'],
            'site_code' => ['required', 'string', 'max:64'],
            'local_user_id' => ['required', 'string', 'max:64'],
            'identity' => ['required', 'array'],
            'identity.type' => ['required', 'string', 'max:64'],
            'identity.email' => ['nullable', 'string', 'max:255'],
            'identity.google_subject' => ['nullable', 'string', 'max:255'],
            'identity.mobile_e164' => ['nullable', 'string', 'max:32'],
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
            'identity' => $validated['identity'],
        ], JSON_THROW_ON_ERROR));

        $result = $this->idempotency->execute(
            $callerId,
            $validated['idempotency_key'],
            $requestHash,
            function () use ($validated, $correlationId): array {
                $resolved = $this->identityResolve->resolve(
                    siteCode: $validated['site_code'],
                    localUserId: $validated['local_user_id'],
                    identity: $validated['identity'],
                    correlationId: $correlationId !== '' ? $correlationId : null,
                );

                return [
                    'status' => $resolved['status'],
                    'body' => $resolved['body'],
                    'resource_type' => 'customer_identity_resolve',
                    'resource_id' => $validated['site_code'].':'.$validated['local_user_id'],
                ];
            },
        );

        return response()->json($result['body'], $result['status']);
    }
}
