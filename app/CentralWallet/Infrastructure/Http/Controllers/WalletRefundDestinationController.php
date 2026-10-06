<?php

namespace App\CentralWallet\Infrastructure\Http\Controllers;

use App\CentralWallet\Application\WalletRefundDestinationIdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WalletRefundDestinationController
{
    public function __construct(
        private readonly WalletRefundDestinationIdentityService $destinationIdentity,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site_code' => ['required', 'string', 'max:64'],
            'local_user_id' => ['required', 'string', 'max:64'],
            'email' => ['nullable', 'string', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:32'],
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

        $correlationId = (string) $request->attributes->get('central_wallet_correlation_id');
        $result = $this->destinationIdentity->resolve(
            siteCode: $validated['site_code'],
            localUserId: $validated['local_user_id'],
            email: $email !== '' ? $email : null,
            mobile: $mobile !== '' ? $mobile : null,
            correlationId: $correlationId,
        );

        return response()->json($result['body'], $result['status']);
    }
}
