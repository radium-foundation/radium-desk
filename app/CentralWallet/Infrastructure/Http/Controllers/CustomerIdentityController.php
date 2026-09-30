<?php

namespace App\CentralWallet\Infrastructure\Http\Controllers;

use App\CentralWallet\Application\CustomerIdentityResolveService;
use App\CentralWallet\Application\IdempotencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CustomerIdentityController
{
    public function __construct(
        private readonly CustomerIdentityResolveService $resolver,
        private readonly IdempotencyService $idempotency,
    ) {}

    public function resolve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:128'],
            'site_code' => ['required', 'string', 'max:64'],
            'local_user_id' => ['required', 'string', 'max:64'],
            'identity' => ['required', 'array'],
            'identity.type' => ['required', 'string', 'max:32'],
            'identity.google_subject' => ['nullable', 'string', 'max:255'],
            'identity.email' => ['nullable', 'string', 'max:255'],
            'identity.mobile_e164' => ['nullable', 'string', 'max:32'],
        ]);

        $callerSite = trim((string) $request->header('X-Site-Code', ''));
        if ($callerSite === '') {
            $callerSite = (string) $request->attributes->get('central_wallet_caller_id');
        }

        if ($callerSite !== $validated['site_code']) {
            return response()->json(['error' => 'site_mismatch'], 403);
        }

        $siteCode = $validated['site_code'];
        $callerId = (string) $request->attributes->get('central_wallet_caller_id');
        $correlationId = (string) $request->attributes->get('central_wallet_correlation_id');
        $requestHash = hash('sha256', json_encode($validated, JSON_THROW_ON_ERROR));

        $result = $this->idempotency->execute(
            $callerId,
            $validated['idempotency_key'],
            $requestHash,
            fn (): array => $this->resolver->resolve(
                siteCode: $siteCode,
                localUserId: $validated['local_user_id'],
                identity: $validated['identity'],
                correlationId: $correlationId,
            ),
        );

        return response()->json($result['body'], $result['status']);
    }
}
