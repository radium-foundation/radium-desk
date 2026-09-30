<?php

namespace App\CentralWallet\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCentralWalletProvisionalIdentityEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('central_wallet.provisional_identity.enabled', false)) {
            return response()->json(['error' => 'provisional_identity_disabled'], 503);
        }

        return $next($request);
    }
}
