<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OemWarrantyCaptureMethod;
use App\Http\Controllers\Controller;
use App\Services\OemWarranty\OemWarrantyLookupSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OemWarrantySessionDraftController extends Controller
{
    public function __construct(
        private readonly OemWarrantyLookupSessionService $sessionService,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'handoff_token' => ['required', 'string', 'min:32', 'max:128'],
            'capture_method' => ['required', 'in:extension_dom,manual_entry'],
            'outcome' => ['required', 'string'],
            'rows' => ['nullable', 'array'],
            'provider_raw' => ['nullable'],
        ]);

        $session = $this->sessionService->submitDraft(
            $validated['handoff_token'],
            OemWarrantyCaptureMethod::from($validated['capture_method']),
            $validated,
        );

        return response()->json([
            'session_public_id' => $session->public_id,
            'order_id' => $session->order_id,
            'status' => $session->status->value,
        ]);
    }
}
