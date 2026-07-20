<?php

namespace App\Http\Controllers;

use App\Http\Requests\BonvoiceClickToCallRequest;
use App\Services\Bonvoice\BonvoiceClickToCallContextResolver;
use App\Services\Bonvoice\BonvoiceClickToCallService;
use Illuminate\Http\JsonResponse;

class BonvoiceClickToCallController extends Controller
{
    public function __construct(
        private readonly BonvoiceClickToCallContextResolver $contextResolver,
        private readonly BonvoiceClickToCallService $clickToCallService,
    ) {}

    public function __invoke(BonvoiceClickToCallRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $context = $this->contextResolver->resolve(
            user: $request->user(),
            orderId: isset($validated['order_id']) ? (int) $validated['order_id'] : null,
            incidentId: isset($validated['incident_id']) ? (int) $validated['incident_id'] : null,
        );

        $fallbackTel = $context->customerPhone !== '' ? 'tel:'.$context->customerPhone : null;

        if ($context->customerDialable === '') {
            return response()->json([
                'success' => false,
                'message' => 'No customer phone number is available for calling.',
                'fallback_tel' => null,
                'fallback_available' => false,
            ], 422);
        }

        if (! $this->clickToCallService->isEnabled()) {
            return response()->json([
                'success' => false,
                'message' => 'Automatic calling failed.',
                'fallback_tel' => $fallbackTel,
                'fallback_available' => $fallbackTel !== null,
                'retriable' => false,
            ], 503);
        }

        $result = $this->clickToCallService->initiateCall(
            agent: $request->user(),
            context: $context,
        );

        if (! $result->success) {
            return response()->json([
                'success' => false,
                'message' => $result->errorMessage ?? 'Automatic calling failed.',
                'fallback_tel' => $fallbackTel,
                'fallback_available' => $fallbackTel !== null,
                'retriable' => $result->retriable,
            ], $result->httpStatus && $result->httpStatus >= 400 ? $result->httpStatus : 422);
        }

        return response()->json([
            'success' => true,
            'message' => $result->message,
            'event_id' => $result->eventId,
            'fallback_tel' => $fallbackTel,
            'fallback_available' => $fallbackTel !== null,
        ]);
    }
}
