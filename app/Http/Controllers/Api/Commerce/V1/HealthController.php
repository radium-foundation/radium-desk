<?php

namespace App\Http\Controllers\Api\Commerce\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'service' => 'radium-desk-commerce',
            'api_version' => (string) config('commerce.api_version', 'v1'),
            'commerce_enabled' => (bool) config('commerce.enabled'),
            'application' => (string) config('app.name'),
        ]);
    }
}
