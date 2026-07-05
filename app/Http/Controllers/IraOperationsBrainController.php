<?php

namespace App\Http\Controllers;

use App\Enums\IraInsightFeedbackResponse;
use App\Enums\IraInsightType;
use App\Http\Requests\IraInsightFeedbackRequest;
use App\Services\Operations\IraInsightFeedbackService;
use App\Services\Operations\IraOperationsBrainService;
use Illuminate\Http\JsonResponse;

class IraOperationsBrainController extends Controller
{
    public function __construct(
        private readonly IraOperationsBrainService $brainService,
        private readonly IraInsightFeedbackService $feedbackService,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless($request->user()?->can('operations-dashboard.view'), 403);

            return $next($request);
        });
    }

    public function feedback(IraInsightFeedbackRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $feedback = $this->feedbackService->record(
            insightKey: (string) $validated['insight_key'],
            insightType: IraInsightType::from((string) $validated['insight_type']),
            response: IraInsightFeedbackResponse::from((string) $validated['response']),
            insightPayload: is_array($validated['insight_payload'] ?? null) ? $validated['insight_payload'] : [],
            user: $request->user(),
        );

        $this->brainService->invalidateCache();

        return response()->json([
            'message' => 'Feedback recorded.',
            'feedback' => [
                'id' => $feedback->id,
                'insight_key' => $feedback->insight_key,
                'response' => $feedback->response->value,
            ],
        ]);
    }
}
