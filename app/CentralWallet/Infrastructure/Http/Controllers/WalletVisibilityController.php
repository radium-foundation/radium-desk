<?php

namespace App\CentralWallet\Infrastructure\Http\Controllers;

use App\CentralWallet\Application\WalletVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WalletVisibilityController
{
    public function __construct(
        private readonly WalletVisibilityService $walletVisibility,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site_code' => ['required', 'string', 'max:64'],
            'local_user_id' => ['required', 'string', 'max:64'],
            'email' => ['nullable', 'string', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:32'],
            'email_verified' => ['nullable', 'string', 'max:8'],
        ]);

        $email = trim((string) ($validated['email'] ?? ''));
        $mobile = trim((string) ($validated['mobile'] ?? ''));
        if ($email === '' && $mobile === '') {
            return response()->json(['error' => 'contact_data_required'], 422);
        }

        $callerSite = trim((string) $request->header('X-Site-Code', ''));
        if ($callerSite === '') {
            $callerSite = (string) $request->attributes->get('central_wallet_caller_id');
        }

        if ($callerSite !== $validated['site_code']) {
            return response()->json(['error' => 'site_mismatch'], 403);
        }

        $emailVerified = filter_var(
            $validated['email_verified'] ?? '0',
            FILTER_VALIDATE_BOOLEAN,
        );

        $correlationId = (string) $request->attributes->get('central_wallet_correlation_id');

        $result = $this->walletVisibility->resolve(
            siteCode: $validated['site_code'],
            localUserId: $validated['local_user_id'],
            email: $email !== '' ? $email : null,
            mobile: $mobile !== '' ? $mobile : null,
            emailVerifiedBySpoke: $emailVerified,
            correlationId: $correlationId !== '' ? $correlationId : null,
        );

        return response()->json($result['body'], $result['status']);
    }
}
