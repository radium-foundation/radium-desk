<?php

namespace App\Services\OemWarranty;

use App\Enums\OemWarrantyCaptureMethod;
use App\Enums\OemWarrantyLookupSessionStatus;
use App\Enums\OemWarrantyProvider;
use App\Models\OemWarrantyLookupSession;
use App\Models\Order;
use App\Models\User;
use App\Support\Orders\OrderSerialOwnership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class OemWarrantyLookupSessionService
{
    public function __construct(
        private readonly OemWarrantyResultValidator $resultValidator,
    ) {}

    /**
     * @return array{session: OemWarrantyLookupSession, handoff_token: string}
     */
    public function start(Order $order, User $user, OemWarrantyProvider $provider): array
    {
        $serial = OrderSerialOwnership::normalizeSerial($order->serial_number);
        if ($serial === null) {
            throw ValidationException::withMessages([
                'serial_number' => 'Assign a serial number before running an OEM warranty check.',
            ]);
        }

        return DB::transaction(function () use ($order, $user, $provider, $serial) {
            $this->cancelActiveSessions($order, $provider);

            $handoffToken = Str::random(64);

            $session = OemWarrantyLookupSession::query()->create([
                'public_id' => (string) Str::uuid(),
                'order_id' => $order->id,
                'user_id' => $user->id,
                'provider' => $provider,
                'serial_number' => $serial,
                'status' => OemWarrantyLookupSessionStatus::OpeningPortal,
                'handoff_token_hash' => hash('sha256', $handoffToken),
                'expires_at' => now()->addSeconds((int) config('oem_warranty.session_ttl_seconds', 900)),
            ]);

            return ['session' => $session, 'handoff_token' => $handoffToken];
        });
    }

    public function markAwaitingVerification(OemWarrantyLookupSession $session): OemWarrantyLookupSession
    {
        $session->status = OemWarrantyLookupSessionStatus::AwaitingVerification;
        $session->save();

        return $session;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function submitDraft(
        string $handoffToken,
        OemWarrantyCaptureMethod $captureMethod,
        array $payload,
    ): OemWarrantyLookupSession {
        $session = $this->resolveByHandoffToken($handoffToken);

        if ($captureMethod === OemWarrantyCaptureMethod::ExtensionDom && ! config('oem_warranty.extension_dom_enabled')) {
            throw ValidationException::withMessages([
                'capture_method' => 'Automated browser capture is disabled. Enter results manually in Desk.',
            ]);
        }

        if (isset($payload['provider']) && (string) $payload['provider'] !== $session->provider->value) {
            throw ValidationException::withMessages([
                'provider' => 'Submitted provider does not match the active lookup session.',
            ]);
        }

        if ($session->status === OemWarrantyLookupSessionStatus::DraftReady && $session->draft_payload !== null) {
            throw ValidationException::withMessages([
                'handoff_token' => 'This lookup session already received a draft.',
            ]);
        }

        if ($session->draft_submitted_at !== null) {
            throw ValidationException::withMessages([
                'handoff_token' => 'This lookup session already consumed its draft submission.',
            ]);
        }

        $validated = $this->resultValidator->validateDraft(
            $session->provider,
            $session->serial_number,
            $captureMethod,
            $payload,
        );

        if (OrderSerialOwnership::normalizeSerial((string) ($payload['serial_number'] ?? $session->serial_number)) !== $session->serial_number) {
            throw ValidationException::withMessages([
                'serial_number' => 'Submitted serial does not match the active lookup session.',
            ]);
        }

        $session->draft_payload = $validated;
        $session->draft_submitted_at = now();
        $session->status = OemWarrantyLookupSessionStatus::DraftReady;
        $session->save();

        return $session;
    }

    public function cancel(OemWarrantyLookupSession $session): void
    {
        if ($session->status->isActive()) {
            $session->status = OemWarrantyLookupSessionStatus::Cancelled;
            $session->save();
        }
    }

    public function resolveByHandoffToken(string $handoffToken): OemWarrantyLookupSession
    {
        $hash = hash('sha256', $handoffToken);

        /** @var OemWarrantyLookupSession|null $session */
        $session = OemWarrantyLookupSession::query()
            ->where('handoff_token_hash', $hash)
            ->first();

        if ($session === null) {
            throw new ModelNotFoundException('Lookup session not found.');
        }

        if ($session->isExpired()) {
            $session->status = OemWarrantyLookupSessionStatus::Expired;
            $session->save();
            throw ValidationException::withMessages(['handoff_token' => 'Lookup session expired.']);
        }

        if (! $session->status->isActive()) {
            throw ValidationException::withMessages(['handoff_token' => 'Lookup session is no longer active.']);
        }

        return $session;
    }

    public function assertOwnedBy(OemWarrantyLookupSession $session, User $user): void
    {
        if ((int) $session->user_id !== (int) $user->id) {
            throw new AuthorizationException('Lookup session belongs to another user.');
        }
    }

    private function cancelActiveSessions(Order $order, OemWarrantyProvider $provider): void
    {
        OemWarrantyLookupSession::query()
            ->where('order_id', $order->id)
            ->where('provider', $provider->value)
            ->whereIn('status', array_map(
                static fn (OemWarrantyLookupSessionStatus $status): string => $status->value,
                array_filter(
                    OemWarrantyLookupSessionStatus::cases(),
                    static fn (OemWarrantyLookupSessionStatus $status): bool => $status->isActive(),
                ),
            ))
            ->update(['status' => OemWarrantyLookupSessionStatus::Cancelled->value]);
    }
}
