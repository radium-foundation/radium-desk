<?php

namespace App\Services\DashboardOemHandoff;

use App\Enums\DashboardOemHandoffSessionStatus;
use App\Models\DashboardOemHandoffSession;
use App\Models\Incident;
use App\Models\User;
use App\Support\Oem\OemWarrantyCheckVendor;
use App\Support\Oem\OemWarrantyCheckVendorResolver;
use App\Support\Orders\OrderSerialOwnership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class DashboardOemHandoffSessionService
{
    public function __construct(
        private readonly MantraHandoffResultValidator $resultValidator,
        private readonly OemWarrantyCheckVendorResolver $vendorResolver,
    ) {}

    /**
     * @return array{session: DashboardOemHandoffSession, handoff_token: string, mantra_capture_enabled: bool}
     */
    public function start(Incident $incident, User $user): array
    {
        if (! config('dashboard_oem_handoff.enabled')) {
            throw ValidationException::withMessages([
                'handoff' => ['Dashboard OEM handoff sessions are disabled.'],
            ]);
        }

        $order = $incident->order;
        if ($order === null) {
            throw ValidationException::withMessages(['order' => ['This service case has no order.']]);
        }

        $vendor = $this->vendorResolver->resolveForOrder($order);
        if ($vendor !== OemWarrantyCheckVendor::Mantra) {
            throw ValidationException::withMessages([
                'oem' => ['Only Mantra (MIS 100 / MFS 110) handoff is available in this release.'],
            ]);
        }

        $serial = OrderSerialOwnership::normalizeSerial($order->serial_number);
        if ($serial === null) {
            throw ValidationException::withMessages([
                'serial_number' => ['Assign a serial number before starting an OEM handoff.'],
            ]);
        }

        $model = trim((string) ($order->displayDeviceModelName() ?? ''));
        $mantraCapture = (bool) config('dashboard_oem_handoff.mantra_result_capture_enabled');

        return DB::transaction(function () use ($incident, $user, $vendor, $serial, $model, $mantraCapture) {
            $this->cancelActiveSessions($incident, $user);

            $handoffToken = Str::random(64);

            $session = DashboardOemHandoffSession::query()->create([
                'public_id' => (string) Str::uuid(),
                'incident_id' => $incident->id,
                'user_id' => $user->id,
                'oem' => $vendor->value,
                'device_model' => $model,
                'serial_number' => $serial,
                'status' => DashboardOemHandoffSessionStatus::OpeningPortal,
                'handoff_token_hash' => hash('sha256', $handoffToken),
                'expires_at' => now()->addSeconds((int) config('dashboard_oem_handoff.session_ttl_seconds', 600)),
            ]);

            return [
                'session' => $session,
                'handoff_token' => $handoffToken,
                'mantra_capture_enabled' => $mantraCapture,
            ];
        });
    }

    public function markAwaitingVerification(DashboardOemHandoffSession $session): DashboardOemHandoffSession
    {
        if ($session->status === DashboardOemHandoffSessionStatus::OpeningPortal) {
            $session->status = DashboardOemHandoffSessionStatus::AwaitingVerification;
            $session->save();
        }

        return $session;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function submitDraft(string $handoffToken, User $user, array $payload): DashboardOemHandoffSession
    {
        if (! config('dashboard_oem_handoff.mantra_result_capture_enabled')) {
            throw ValidationException::withMessages([
                'capture' => ['Mantra DOM result capture is disabled.'],
            ]);
        }

        $session = $this->resolveByHandoffToken($handoffToken);
        $this->assertOwnedBy($session, $user);

        if ($session->oem !== OemWarrantyCheckVendor::Mantra->value) {
            throw ValidationException::withMessages(['oem' => ['Only Mantra draft capture is supported.']]);
        }

        if ($session->draft_submitted_at !== null || $session->status === DashboardOemHandoffSessionStatus::DraftReady) {
            throw ValidationException::withMessages(['handoff_token' => ['This session already received a draft.']]);
        }

        $validated = $this->resultValidator->validateDraft($session->serial_number, $payload);

        $session->draft_payload = $validated;
        $session->draft_submitted_at = now();
        $session->status = DashboardOemHandoffSessionStatus::DraftReady;
        $session->save();

        return $session;
    }

    public function markReviewed(DashboardOemHandoffSession $session, User $user): DashboardOemHandoffSession
    {
        $this->assertOwnedBy($session, $user);

        if ($session->status !== DashboardOemHandoffSessionStatus::DraftReady) {
            throw ValidationException::withMessages(['session' => ['No draft is ready for review.']]);
        }

        $session->draft_reviewed_at = now();
        $session->status = DashboardOemHandoffSessionStatus::Reviewed;
        $session->save();

        return $session;
    }

    public function resolveByHandoffToken(string $handoffToken): DashboardOemHandoffSession
    {
        $hash = hash('sha256', $handoffToken);

        /** @var DashboardOemHandoffSession|null $session */
        $session = DashboardOemHandoffSession::query()
            ->where('handoff_token_hash', $hash)
            ->first();

        if ($session === null) {
            throw new ModelNotFoundException('Handoff session not found.');
        }

        if ($session->isExpired()) {
            $session->status = DashboardOemHandoffSessionStatus::Expired;
            $session->save();
            throw ValidationException::withMessages(['handoff_token' => ['Handoff session expired.']]);
        }

        if (! $session->status->isActive() && $session->status !== DashboardOemHandoffSessionStatus::DraftReady) {
            throw ValidationException::withMessages(['handoff_token' => ['Handoff session is no longer active.']]);
        }

        return $session;
    }

    public function assertOwnedBy(DashboardOemHandoffSession $session, User $user): void
    {
        if ((int) $session->user_id !== (int) $user->id) {
            throw new AuthorizationException('Handoff session belongs to another user.');
        }

    }

    public function assertForIncident(DashboardOemHandoffSession $session, Incident $incident): void
    {
        if ((int) $session->incident_id !== (int) $incident->id) {
            throw new AuthorizationException('Handoff session does not belong to this service case.');
        }
    }

    private function cancelActiveSessions(Incident $incident, User $user): void
    {
        DashboardOemHandoffSession::query()
            ->where('incident_id', $incident->id)
            ->where('user_id', $user->id)
            ->whereIn('status', [
                DashboardOemHandoffSessionStatus::OpeningPortal->value,
                DashboardOemHandoffSessionStatus::AwaitingVerification->value,
                DashboardOemHandoffSessionStatus::DraftReady->value,
            ])
            ->update(['status' => DashboardOemHandoffSessionStatus::Cancelled->value]);
    }
}
