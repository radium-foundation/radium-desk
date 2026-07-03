<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InteraktFlowWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        return response()->json(['status' => 'not_implemented'], 501);
    }
}
