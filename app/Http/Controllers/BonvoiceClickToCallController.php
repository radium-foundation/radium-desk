<?php

namespace App\Http\Controllers;

use App\Enums\BonvoiceClickToCallFailureCode;
use App\Http\Requests\BonvoiceClickToCallRequest;
use App\Services\Bonvoice\BonvoiceClickToCallContextResolver;
use App\Services\Bonvoice\BonvoiceClickToCallMetrics;
use App\Services\Bonvoice\BonvoiceClickToCallService;
use Illuminate\Http\JsonResponse;

class BonvoiceClickToCallController extends Controller
{
    public function __construct(
        private readonly BonvoiceClickToCallContextResolver $contextResolver,
        private readonly BonvoiceClickToCallService $clickToCallService,
        private readonly BonvoiceClickToCallMetrics $metrics,
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
            $correlationId = $this->clickToCallService->generateEventId();
            $this->metrics->recordFailure(
                failureCode: BonvoiceClickToCallFailureCode::CustomerPhone,
                correlationId: $correlationId,
            );

            return response()->json([
                'success' => false,
                'message' => BonvoiceClickToCallFailureCode::CustomerPhone->userMessage(),
                'failure_code' => BonvoiceClickToCallFailureCode::CustomerPhone->value,
                'correlation_id' => $correlationId,
                'event_id' => null,
                'fallback_tel' => null,
                'fallback_available' => false,
                'retriable' => false,
            ], 422);
        }

        if (! $this->clickToCallService->isEnabled()) {
            $correlationId = $this->clickToCallService->generateEventId();
            $this->metrics->recordFailure(
                failureCode: BonvoiceClickToCallFailureCode::Disabled,
                correlationId: $correlationId,
            );

            return response()->json([
                'success' => false,
                'message' => BonvoiceClickToCallFailureCode::Disabled->userMessage(),
                'failure_code' => BonvoiceClickToCallFailureCode::Disabled->value,
                'correlation_id' => $correlationId,
                'event_id' => null,
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
            $failureCode = $result->failureCode ?? BonvoiceClickToCallFailureCode::InvalidResponse;

            return response()->json([
                'success' => false,
                'message' => $failureCode->userMessage(),
                'failure_code' => $failureCode->value,
                'correlation_id' => $result->correlationId,
                'event_id' => $result->eventId,
                'fallback_tel' => $fallbackTel,
                'fallback_available' => $fallbackTel !== null,
                'retriable' => $result->retriable,
            ], $result->httpStatus && $result->httpStatus >= 400 ? $result->httpStatus : 422);
        }

        return response()->json([
            'success' => true,
            'message' => $result->message,
            'event_id' => $result->eventId,
            'correlation_id' => $result->correlationId ?? $result->eventId,
            'fallback_tel' => $fallbackTel,
            'fallback_available' => $fallbackTel !== null,
        ]);
    }
}
