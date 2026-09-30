<?php

namespace App\CentralWallet\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCentralWalletCustomerIdentityEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('central_wallet.customer_identity.enabled', false)) {
            return response()->json(['error' => 'customer_identity_disabled'], 503);
        }

        return $next($request);
    }
}
