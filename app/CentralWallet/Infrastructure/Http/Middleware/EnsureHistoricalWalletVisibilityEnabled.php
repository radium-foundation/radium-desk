<?php

namespace App\CentralWallet\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureHistoricalWalletVisibilityEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('central_wallet.historical_wallet_visibility.enabled', false)) {
            return response()->json(['error' => 'historical_wallet_visibility_disabled'], 503);
        }

        return $next($request);
    }
}
