<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\DashboardOemHandoffSession;
use App\Models\Incident;
use App\Services\DashboardOemHandoff\DashboardOemHandoffSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardOemHandoffSessionController extends Controller
{
    public function __construct(
        private readonly DashboardOemHandoffSessionService $sessionService,
    ) {}

    public function store(Request $request, Incident $incident): JsonResponse
    {
        $this->authorize('view', $incident);

        $result = $this->sessionService->start($incident, $request->user());
        $session = $result['session'];
        $this->sessionService->markAwaitingVerification($session);

        return response()->json([
            'session_public_id' => $session->public_id,
            'incident_id' => $session->incident_id,
            'oem' => $session->oem,
            'device_model' => $session->device_model,
            'expires_at' => $session->expires_at->toIso8601String(),
            'handoff_token' => $result['handoff_token'],
            'mantra_capture_enabled' => $result['mantra_capture_enabled'],
            'status' => $session->status->value,
        ]);
    }

    public function show(Request $request, Incident $incident, string $sessionPublicId): JsonResponse
    {
        $this->authorize('view', $incident);

        $session = DashboardOemHandoffSession::query()
            ->where('public_id', $sessionPublicId)
            ->where('incident_id', $incident->id)
            ->firstOrFail();

        $this->sessionService->assertOwnedBy($session, $request->user());

        return response()->json([
            'session' => $this->sessionPayload($session),
        ]);
    }

    public function submitDraft(Request $request, Incident $incident, string $sessionPublicId): JsonResponse
    {
        $this->authorize('view', $incident);

        $validated = $request->validate([
            'handoff_token' => ['required', 'string', 'min:32', 'max:128'],
            'outcome' => ['required', 'string', 'in:found,not_found,inconclusive'],
            'rows' => ['nullable', 'array'],
        ]);

        $session = DashboardOemHandoffSession::query()
            ->where('public_id', $sessionPublicId)
            ->where('incident_id', $incident->id)
            ->firstOrFail();

        $this->sessionService->assertForIncident($session, $incident);

        $tokenSession = $this->sessionService->resolveByHandoffToken($validated['handoff_token']);
        if ($tokenSession->public_id !== $session->public_id) {
            abort(403);
        }

        $updated = $this->sessionService->submitDraft(
            $validated['handoff_token'],
            $request->user(),
            $validated,
        );

        return response()->json([
            'session' => $this->sessionPayload($updated),
        ]);
    }

    public function review(Request $request, Incident $incident, string $sessionPublicId): JsonResponse
    {
        $this->authorize('view', $incident);

        $session = DashboardOemHandoffSession::query()
            ->where('public_id', $sessionPublicId)
            ->where('incident_id', $incident->id)
            ->firstOrFail();

        $this->sessionService->assertForIncident($session, $incident);
        $updated = $this->sessionService->markReviewed($session, $request->user());

        return response()->json([
            'session' => $this->sessionPayload($updated),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionPayload(DashboardOemHandoffSession $session): array
    {
        $draft = $session->draft_payload;

        return [
            'public_id' => $session->public_id,
            'incident_id' => $session->incident_id,
            'oem' => $session->oem,
            'device_model' => $session->device_model,
            'status' => $session->status->value,
            'expires_at' => $session->expires_at->toIso8601String(),
            'draft_submitted_at' => $session->draft_submitted_at?->toIso8601String(),
            'draft_reviewed_at' => $session->draft_reviewed_at?->toIso8601String(),
            'draft' => $draft ? [
                'oem' => $draft['oem'] ?? 'mantra',
                'outcome' => $draft['outcome'] ?? 'inconclusive',
                'serial_number' => $draft['serial_number'] ?? null,
                'model' => $draft['model'] ?? null,
                'warranty_expiry_date' => $draft['warranty_expiry_date'] ?? null,
                'rd_expiry_date' => $draft['rd_expiry_date'] ?? null,
                'capture_method' => $draft['capture_method'] ?? 'extension_dom',
                'captured_at' => $draft['captured_at'] ?? null,
                'warranty_expiry_label' => 'OEM-reported warranty expiry (not verified)',
                'rd_expiry_label' => 'OEM-reported RD subscription expiry (not warranty)',
            ] : null,
        ];
    }
}
