<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CashfreeWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        Log::info('[Cashfree Webhook] Received', [
            'method' => $request->method(),
            'ip' => $request->ip(),
            'url' => $request->fullUrl(),
            'headers' => $request->headers->all(),
            'payload' => $request->all(),
            'raw_body' => $request->getContent(),
        ]);

        return response()->json(['status' => 'ok'], 200);
    }
}
