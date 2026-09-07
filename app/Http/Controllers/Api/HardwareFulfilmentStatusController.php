<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HardwareFulfilment\HardwareFulfilmentInboundCallbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HardwareFulfilmentStatusController extends Controller
{
    public function __construct(
        private readonly HardwareFulfilmentInboundCallbackService $inbound,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $result = $this->inbound->handle($request);

        return response()->json($result['body'], $result['http']);
    }
}
