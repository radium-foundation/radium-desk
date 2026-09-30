<?php

namespace App\CentralWallet\Infrastructure\Http\Controllers;

use App\CentralWallet\Application\IdempotencyService;
use App\CentralWallet\Application\ProvisionalIdentityResolveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProvisionalIdentityController
{
    public function __construct(
        private readonly ProvisionalIdentityResolveService $resolver,
        private readonly IdempotencyService $idempotency,
    ) {}

    public function resolve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:128'],
            'site_code' => ['required', 'string', 'max:64'],
            'local_user_id' => ['required', 'string', 'max:64'],
            'email' => ['required', 'string', 'max:255'],
            'email_verified' => ['required', 'boolean'],
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
        $requestHash = hash('sha256', json_encode($validated, JSON_THROW_ON_ERROR));

        $result = $this->idempotency->execute(
            $callerId,
            $validated['idempotency_key'],
            $requestHash,
            fn (): array => $this->resolver->resolve(
                siteCode: $validated['site_code'],
                localUserId: $validated['local_user_id'],
                email: $validated['email'],
                emailVerified: (bool) $validated['email_verified'],
                correlationId: $correlationId,
            ),
        );

        return response()->json($result['body'], $result['status']);
    }
}
