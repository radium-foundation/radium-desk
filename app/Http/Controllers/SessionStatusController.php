<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionStatusController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user() !== null, 401);

        return response()->json([
            'authenticated' => true,
        ]);
    }
}