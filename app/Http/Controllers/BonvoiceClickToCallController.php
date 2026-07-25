<?php

namespace App\Http\Controllers;

use App\Enums\BonvoiceClickToCallFailureCode;
use App\Http\Requests\BonvoiceClickToCallRequest;
use App\Services\Bonvoice\BonvoiceClickToCallContextResolver;
use App\Services\Bonvoice\BonvoiceClickToCallMetrics;
use App\Services\Bonvoice\BonvoiceClickToCallService;
use App\Services\Bonvoice\BonvoiceOutboundClickToCallLiveStatusService;
use App\Support\Bonvoice\BonvoiceClickToCallSupportReference;
use Illuminate\Http\JsonResponse;

class BonvoiceClickToCallController extends Controller
{
    public function __construct(
        private readonly BonvoiceClickToCallContextResolver $contextResolver,
        private readonly BonvoiceClickToCallService $clickToCallService,
        private readonly BonvoiceClickToCallMetrics $metrics,
        private readonly BonvoiceOutboundClickToCallLiveStatusService $outboundLiveStatusService,
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
        $logContext = [
            'user_id' => $request->user()->id,
            'incident_id' => $context->incidentId(),
            'order_id' => $context->orderId(),
        ];

        if ($context->customerDialable === '') {
            return $this->controllerFailureResponse(
                failureCode: BonvoiceClickToCallFailureCode::CustomerPhone,
                message: BonvoiceClickToCallFailureCode::CustomerPhone->userMessage(),
                fallbackTel: null,
                logContext: $logContext,
                status: 422,
            );
        }

        if (! $this->clickToCallService->isEnabled()) {
            return $this->controllerFailureResponse(
                failureCode: BonvoiceClickToCallFailureCode::Disabled,
                message: BonvoiceClickToCallFailureCode::Disabled->userMessage(),
                fallbackTel: $fallbackTel,
                logContext: $logContext,
                status: 503,
            );
        }

        $result = $this->clickToCallService->initiateCall(
            agent: $request->user(),
            context: $context,
        );

        if (! $result->success) {
            $failureCode = $result->failureCode ?? BonvoiceClickToCallFailureCode::InvalidResponse;

            return $this->failureResponse(
                failureCode: $failureCode,
                message: $failureCode->userMessage(),
                referenceId: $result->referenceId,
                correlationId: $result->correlationId,
                eventId: $result->eventId,
                retriable: $result->retriable,
                fallbackTel: $fallbackTel,
                status: $result->httpStatus && $result->httpStatus >= 400 ? $result->httpStatus : 422,
            );
        }

        $this->outboundLiveStatusService->broadcastStarted(
            agent: $request->user(),
            eventId: (string) $result->eventId,
            context: $context,
        );

        return response()->json([
            'success' => true,
            'message' => $result->message,
            'event_id' => $result->eventId,
            'correlation_id' => $result->correlationId ?? $result->eventId,
            'fallback_tel' => $fallbackTel,
            'fallback_available' => $fallbackTel !== null,
        ]);
    }

    /**
     * @param  array<string, int|string|null>  $logContext
     */
    private function controllerFailureResponse(
        BonvoiceClickToCallFailureCode $failureCode,
        string $message,
        ?string $fallbackTel,
        array $logContext,
        int $status,
    ): JsonResponse {
        $correlationId = $this->clickToCallService->generateEventId();
        $referenceId = BonvoiceClickToCallSupportReference::format(correlationId: $correlationId);

        BonvoiceClickToCallSupportReference::logFailure(
            referenceId: $referenceId,
            failureCode: $failureCode,
            context: [
                ...$logContext,
                'correlation_id' => $correlationId,
            ],
            retriable: false,
        );

        $this->metrics->recordFailure(
            failureCode: $failureCode,
            correlationId: $correlationId,
        );

        return $this->failureResponse(
            failureCode: $failureCode,
            message: $message,
            referenceId: $referenceId,
            correlationId: $correlationId,
            eventId: null,
            retriable: false,
            fallbackTel: $fallbackTel,
            status: $status,
        );
    }

    private function failureResponse(
        BonvoiceClickToCallFailureCode $failureCode,
        string $message,
        ?string $referenceId,
        ?string $correlationId,
        ?string $eventId,
        bool $retriable,
        ?string $fallbackTel,
        int $status,
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'failure_code' => $failureCode->value,
            'correlation_id' => $correlationId,
            'reference_id' => $referenceId,
            'event_id' => $eventId,
            'timestamp' => now()->toIso8601String(),
            'fallback_tel' => $fallbackTel,
            'fallback_available' => $fallbackTel !== null,
            'retriable' => $retriable,
        ], $status);
    }
}
