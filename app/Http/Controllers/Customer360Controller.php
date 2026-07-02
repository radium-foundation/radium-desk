<?php

namespace App\Http\Controllers;

use App\Http\Requests\Customer360AIWorkbenchAuditRequest;
use App\Http\Requests\Customer360ExecutiveSummaryTranslationRequest;
use App\Models\Incident;
use App\Services\AI\AIWorkbenchAuditService;
use App\Services\AI\IRAExecutiveSummaryTranslationService;
use App\Services\Customer360Service;
use App\Services\Timeline\Customer360TimelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class Customer360Controller extends Controller
{
    public function __construct(
        private readonly Customer360Service $customer360Service,
        private readonly Customer360TimelineService $customer360TimelineService,
        private readonly AIWorkbenchAuditService $workbenchAuditService,
        private readonly IRAExecutiveSummaryTranslationService $executiveSummaryTranslationService,
    ) {}

    public function show(Incident $incident): Response
    {
        $this->authorize('view', $incident);

        $html = view('customer-360.drawer-content', $this->customer360Service->drawerData($incident))->render();

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
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

    public function timeline(Incident $incident, Request $request): Response
    {
        $this->authorize('view', $incident);

        $offset = max(0, (int) $request->query('offset', 0));
        $viewModel = $this->customer360TimelineService->forIncident($incident, $offset);

        $html = view('customer-360.partials.timeline-page', [
            'viewModel' => $viewModel,
            'loadMoreUrl' => route('dashboard.service-cases.customer-360.timeline', $incident),
        ])->render();

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
