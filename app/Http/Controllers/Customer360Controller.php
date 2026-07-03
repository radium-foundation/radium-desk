<?php

namespace App\Http\Controllers;

use App\Http\Requests\Customer360AIWorkbenchAuditRequest;
use App\Http\Requests\Customer360ExecutiveSummaryTranslationRequest;
use App\Models\Incident;
use App\Models\User;
use App\Services\AI\AIWorkbenchAuditService;
use App\Services\AI\IRAExecutiveSummaryTranslationService;
use App\Services\Customer360Service;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class Customer360Controller extends Controller
{
    public function __construct(
        private readonly Customer360Service $customer360Service,
        private readonly AIWorkbenchAuditService $workbenchAuditService,
        private readonly IRAExecutiveSummaryTranslationService $executiveSummaryTranslationService,
        private readonly RadiumBoxOrderEnrichmentService $radiumBoxOrderEnrichmentService,
    ) {}

    public function show(Incident $incident): Response
    {
        $this->authorize('view', $incident);

        $html = view('customer-360.drawer-content', $this->customer360Service->drawerData($incident))->render();

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function radiumBoxSync(Incident $incident): JsonResponse
    {
        $this->authorize('view', $incident);

        $incident->loadMissing('order');
        $order = $incident->order;

        if ($order === null) {
            return response()->json([
                'success' => false,
                'message' => 'Service case is not linked to an order.',
            ], 422);
        }

        $result = $this->radiumBoxOrderEnrichmentService->manualSync(
            $order,
            $this->authenticatedUser(),
        );

        if (! $result->success) {
            return response()->json([
                'success' => false,
                'message' => $result->message,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $result->message,
            'device_html' => $this->renderDeviceSection($incident->fresh(['order.deviceModel'])),
        ]);
    }

    public function device(Incident $incident): JsonResponse
    {
        $this->authorize('view', $incident);

        $payload = $this->customer360Service->devicePayload($incident);

        return response()->json([
            'html' => $this->renderDeviceSection($incident),
            'should_poll_sync' => (bool) ($payload['device']['should_poll_sync'] ?? false),
        ]);
    }

    private function renderDeviceSection(Incident $incident): string
    {
        $payload = $this->customer360Service->devicePayload($incident);

        return view('customer-360.partials.device-section', [
            'device' => $payload['device'],
            'sync_history' => $payload['sync_history'],
        ])->render();
    }

    private function authenticatedUser(): User
    {
        $user = request()->user();

        if ($user === null) {
            abort(403);
        }

        return $user;
    }

    public function aiWorkbench(Incident $incident): JsonResponse
    {
        $this->authorize('view', $incident);

        $data = $this->customer360Service->refreshAiWorkbench($incident);

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'html' => view('customer-360.partials.ai-workbench', [
                'workbench' => $data,
                'incident' => $incident,
            ])->render(),
        ]);
    }

    public function auditWorkbench(Incident $incident, Customer360AIWorkbenchAuditRequest $request): JsonResponse
    {
        $this->authorize('view', $incident);

        $validated = $request->validated();
        $metadata = array_filter([
            'content_length' => $validated['content_length'] ?? null,
            'content_hash' => $validated['content_hash'] ?? null,
        ], fn (mixed $value): bool => $value !== null);

        match ($validated['action']) {
            'viewed' => $this->workbenchAuditService->recordViewed(
                $incident,
                $request->user()?->id,
                $validated['artifact_key'],
                $request,
                $metadata,
            ),
            'copied' => $this->workbenchAuditService->recordCopied(
                $incident,
                $request->user()?->id,
                $validated['artifact_key'],
                $request,
                $metadata,
            ),
            'inserted' => $this->workbenchAuditService->recordInserted(
                $incident,
                $request->user()?->id,
                $validated['artifact_key'],
                (string) ($validated['target'] ?? 'editor'),
                $request,
                $metadata,
            ),
        };

        return response()->json(['status' => 'ok']);
    }

    public function translateExecutiveSummary(
        Incident $incident,
        Customer360ExecutiveSummaryTranslationRequest $request,
    ): JsonResponse {
        $this->authorize('view', $incident);

        $validated = $request->validated();

        return response()->json(
            $this->executiveSummaryTranslationService->translatePayloadToHindi($validated),
        );
    }

    public function timeline(Incident $incident, Request $request): Response|JsonResponse
    {
        $this->authorize('view', $incident);

        $offset = max(0, (int) $request->query('offset', 0));
        $payload = $this->customer360Service->timelinePayload($incident, $offset);

        if ($request->wantsJson()) {
            return response()->json([
                'html' => $payload['html'],
                'has_more' => $payload['timeline']->hasMore,
                'loaded_count' => $payload['timeline']->loadedCount,
            ]);
        }

        $html = view('customer-360.partials.timeline-page', [
            'viewModel' => $payload['timeline'],
            'loadMoreUrl' => route('dashboard.service-cases.customer-360.timeline', $incident),
        ])->render();

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
