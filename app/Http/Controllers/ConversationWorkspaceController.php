<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateConversationWorkspaceRequest;
use App\Models\Incident;
use App\Services\ConversationWorkspace\ConversationWorkspaceSessionService;
use Illuminate\Http\JsonResponse;

class ConversationWorkspaceController extends Controller
{
    public function __construct(
        private readonly ConversationWorkspaceSessionService $sessionService,
    ) {}

    public function update(
        UpdateConversationWorkspaceRequest $request,
        Incident $incident,
    ): JsonResponse {
        $this->authorize('update', $incident);

        $incident->loadMissing('order');

        if (! config('conversation_workspace.enabled')) {
            return response()->json([
                'success' => false,
                'message' => 'Conversation Workspace is not available.',
            ], 422);
        }

        if ($incident->order === null || ! $incident->order->isInquiryOrder()) {
            return response()->json([
                'success' => false,
                'message' => 'Conversation Workspace is only available for enquiry cases.',
            ], 422);
        }

        if ($incident->inquiry_origin_order_id !== null) {
            return response()->json([
                'success' => false,
                'message' => 'Conversation Workspace is not available after linking an order.',
            ], 422);
        }

        $session = $this->sessionService->firstOrCreateForIncident(
            $incident,
            $request->user(),
            $request->string('call_id')->toString() ?: null,
        );

        $session = $this->sessionService->update(
            $session,
            $request->mappedAttributes(),
            $request->user(),
        );

        return response()->json([
            'success' => true,
            'workspace' => $this->sessionService->viewModel($session),
        ]);
    }
}
