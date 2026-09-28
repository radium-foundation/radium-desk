<?php

namespace App\CentralWallet\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCentralWalletApiEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('central_wallet.enabled', false) || ! config('central_wallet.api_enabled', false)) {
            return response()->json([
                'error' => 'central_wallet_disabled',
                'message' => 'Central Wallet API is disabled.',
            ], 503);
        }

        return $next($request);
    }
}
