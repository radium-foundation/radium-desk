<?php

namespace App\CentralWallet\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCentralWalletReservationsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('central_wallet.reservations.enabled', false)) {
            return response()->json([
                'error' => 'central_wallet_reservations_disabled',
                'message' => 'Central Wallet reservations are disabled.',
            ], 503);
        }

        return $next($request);
    }
}
