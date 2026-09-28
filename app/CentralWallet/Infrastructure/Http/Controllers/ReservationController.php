<?php

namespace App\CentralWallet\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;

final class ReservationController
{
    public function store(): JsonResponse
    {
        return response()->json([
            'error' => 'not_implemented',
            'message' => 'Wallet reservations are not available in Phase 1. Reserved for future RadiumBox checkout expansion.',
        ], 501);
    }
}
