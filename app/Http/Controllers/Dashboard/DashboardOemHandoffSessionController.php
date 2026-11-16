<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\DashboardOemHandoffSession;
use App\Models\Incident;
use App\Services\DashboardOemHandoff\DashboardOemHandoffSessionService;
use App\Services\DashboardOemHandoff\MorphoHandoffServiceReferencePrefill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardOemHandoffSessionController extends Controller
{
    public function __construct(
        private readonly DashboardOemHandoffSessionService $sessionService,
        private readonly MorphoHandoffServiceReferencePrefill $morphoServiceReferencePrefill,
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
            'morpho_capture_enabled' => $result['morpho_capture_enabled'],
            'result_capture_enabled' => $result['result_capture_enabled'],
            'status' => $session->status->value,
        ]);
    }

    public function recover(Request $request, Incident $incident): JsonResponse
    {
        $this->authorize('view', $incident);

        $session = $this->sessionService->recoverRecoverableDraft($incident, $request->user());

        return response()->json([
            'session' => $this->sessionPayload($session),
            'recovered' => true,
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
            'fields' => ['nullable', 'array'],
        ]);

        $session = DashboardOemHandoffSession::query()
            ->where('public_id', $sessionPublicId)
            ->where('incident_id', $incident->id)
            ->firstOrFail();

        $this->sessionService->assertForIncident($session, $incident);
        $this->sessionService->assertOwnedBy($session, $request->user());

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
        return $this->confirm($request, $incident, $sessionPublicId);
    }

    public function confirm(Request $request, Incident $incident, string $sessionPublicId): JsonResponse
    {
        $this->authorize('view', $incident);

        $session = DashboardOemHandoffSession::query()
            ->where('public_id', $sessionPublicId)
            ->where('incident_id', $incident->id)
            ->firstOrFail();

        $this->sessionService->assertForIncident($session, $incident);
        $this->sessionService->assertOwnedBy($session, $request->user());
        $updated = $this->sessionService->confirmResult($session, $request->user());

        return response()->json([
            'session' => $this->sessionPayload($updated),
            'service_reference_prefill' => $this->morphoPrefillPayload($updated),
        ]);
    }

    public function reject(Request $request, Incident $incident, string $sessionPublicId): JsonResponse
    {
        $this->authorize('view', $incident);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $session = DashboardOemHandoffSession::query()
            ->where('public_id', $sessionPublicId)
            ->where('incident_id', $incident->id)
            ->firstOrFail();

        $this->sessionService->assertForIncident($session, $incident);
        $this->sessionService->assertOwnedBy($session, $request->user());
        $updated = $this->sessionService->rejectResult($session, $request->user(), [
            'reason' => $validated['reason'] ?? null,
        ]);

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
            'status_label' => $session->status->displayLabel(),
            'expires_at' => $session->expires_at->toIso8601String(),
            'draft_submitted_at' => $session->draft_submitted_at?->toIso8601String(),
            'draft_reviewed_at' => $session->draft_reviewed_at?->toIso8601String(),
            'confirmed_at' => $session->confirmed_at?->toIso8601String(),
            'rejected_at' => $session->rejected_at?->toIso8601String(),
            'rejection_reason' => $session->rejection_reason,
            'oem_expiry_persist' => [
                'status' => $session->oem_expiry_persist_status,
                'detail' => $session->oem_expiry_persist_detail,
            ],
            'draft' => $draft ? $this->normalizedDraftView($draft) : null,
            'confirmed' => $session->confirmed_payload ? $this->normalizedDraftView($session->confirmed_payload) : null,
            'service_reference_prefill' => $this->morphoPrefillPayload($session),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function morphoPrefillPayload(DashboardOemHandoffSession $session): ?array
    {
        if ($session->oem !== 'morpho') {
            return null;
        }

        $draft = $session->confirmed_payload ?? $session->draft_payload;
        if (! is_array($draft)) {
            return null;
        }

        return $this->morphoServiceReferencePrefill->build($session, $draft);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private function normalizedDraftView(array $draft): array
    {
        $oem = (string) ($draft['oem'] ?? 'mantra');
        $base = [
            'oem' => $oem,
            'outcome' => $draft['outcome'] ?? 'inconclusive',
            'serial_number' => $draft['serial_number'] ?? null,
            'capture_method' => $draft['capture_method'] ?? 'extension_dom',
            'captured_at' => $draft['captured_at'] ?? null,
            'schema_version' => $draft['schema_version'] ?? 1,
            'disclaimer' => 'OEM-reported values are informational only — not verified warranty or renewal entitlement.',
        ];

        if ($oem === 'morpho') {
            $fields = is_array($draft['fields'] ?? null) ? $draft['fields'] : [];

            return array_merge($base, [
                'fields' => $fields,
                'whitelist_qualification' => $draft['whitelist_qualification'] ?? null,
            ]);
        }

        return array_merge($base, [
            'model' => $draft['model'] ?? null,
            'warranty_expiry_date' => $draft['warranty_expiry_date'] ?? null,
            'rd_expiry_date' => $draft['rd_expiry_date'] ?? null,
            'warranty_expiry_label' => 'OEM-reported warranty expiry (not verified)',
            'rd_expiry_label' => 'OEM-reported RD subscription expiry (not warranty)',
        ]);
    }
}
