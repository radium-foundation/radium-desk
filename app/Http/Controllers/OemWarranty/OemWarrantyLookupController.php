<?php

namespace App\Http\Controllers\OemWarranty;

use App\Enums\OemWarrantyProvider;
use App\Http\Controllers\Controller;
use App\Models\OemWarrantyLookupSession;
use App\Models\Order;
use App\Services\OemWarranty\OemWarrantyCheckService;
use App\Services\OemWarranty\OemWarrantyLookupSessionService;
use App\Services\OemWarranty\OemWarrantyProviderResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OemWarrantyLookupController extends Controller
{
    public function __construct(
        private readonly OemWarrantyProviderResolver $providerResolver,
        private readonly OemWarrantyLookupSessionService $sessionService,
        private readonly OemWarrantyCheckService $checkService,
    ) {}

    public function context(Request $request, Order $order): JsonResponse
    {
        $this->authorize('checkOemWarranty', $order);

        $resolution = $this->providerResolver->resolve($order->loadMissing('deviceModel'));

        $checks = $order->oemWarrantyChecks()
            ->where('is_current', true)
            ->orderByDesc('checked_at')
            ->get()
            ->map(static fn ($check): array => [
                'id' => $check->id,
                'provider' => $check->provider->value,
                'provider_label' => $check->provider->label(),
                'outcome' => $check->outcome->value,
                'capture_method' => $check->capture_method->value,
                'checked_at' => $check->checked_at?->toIso8601String(),
                'serial_number' => $check->serial_number,
                'summary' => [
                    'warranty_expiry_date' => $check->warranty_expiry_date?->toDateString(),
                    'rd_expiry_date' => $check->rd_expiry_date?->toDateString(),
                    'mantratec_model' => $check->mantratec_model,
                    'morpho_order_number' => $check->morpho_order_number,
                    'morpho_whitelisting_date' => $check->morpho_whitelisting_date?->toDateString(),
                    'morpho_expiry_date' => $check->morpho_expiry_date?->toDateString(),
                    'morpho_validity_years' => $check->morpho_validity_years,
                ],
            ]);

        $activeSessions = $order->oemWarrantyLookupSessions()
            ->where('user_id', $request->user()?->id)
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->filter(static fn (OemWarrantyLookupSession $session): bool => $session->status->isActive())
            ->values()
            ->map(static fn (OemWarrantyLookupSession $session): array => [
                'public_id' => $session->public_id,
                'provider' => $session->provider->value,
                'status' => $session->status->value,
                'expires_at' => $session->expires_at?->toIso8601String(),
                'has_draft' => $session->draft_payload !== null,
            ]);

        return response()->json([
            'suggested_provider' => $resolution['suggested']?->value,
            'requires_provider_selection' => $resolution['requires_selection'],
            'provider_reason' => $resolution['reason'],
            'providers' => array_map(static fn (OemWarrantyProvider $provider): array => [
                'value' => $provider->value,
                'label' => $provider->label(),
                'portal_url' => $provider->portalUrl(),
            ], OemWarrantyProvider::cases()),
            'current_checks' => $checks,
            'active_sessions' => $activeSessions,
            'extension_id' => config('oem_warranty.extension_id'),
        ]);
    }

    public function start(Request $request, Order $order): JsonResponse
    {
        $this->authorize('checkOemWarranty', $order);

        $validated = $request->validate([
            'provider' => ['required', Rule::enum(OemWarrantyProvider::class)],
        ]);

        $provider = OemWarrantyProvider::from($validated['provider']);
        $started = $this->sessionService->start($order, $request->user(), $provider);

        return response()->json([
            'session' => [
                'public_id' => $started['session']->public_id,
                'provider' => $provider->value,
                'provider_label' => $provider->label(),
                'portal_url' => $provider->portalUrl(),
                'serial_number' => $started['session']->serial_number,
                'status' => $started['session']->status->value,
                'expires_at' => $started['session']->expires_at?->toIso8601String(),
            ],
            'handoff_token' => $started['handoff_token'],
        ]);
    }

    public function showSession(Request $request, Order $order, OemWarrantyLookupSession $oemWarrantyLookupSession): JsonResponse
    {
        $this->authorize('checkOemWarranty', $order);
        $this->assertSessionForOrder($oemWarrantyLookupSession, $order);
        $this->sessionService->assertOwnedBy($oemWarrantyLookupSession, $request->user());

        $session = $oemWarrantyLookupSession;

        return response()->json([
            'session' => [
                'public_id' => $session->public_id,
                'provider' => $session->provider->value,
                'status' => $session->status->value,
                'expires_at' => $session->expires_at?->toIso8601String(),
                'draft' => $session->draft_payload,
            ],
        ]);
    }

    public function cancel(Request $request, Order $order, OemWarrantyLookupSession $oemWarrantyLookupSession): JsonResponse
    {
        $this->authorize('checkOemWarranty', $order);
        $this->assertSessionForOrder($oemWarrantyLookupSession, $order);
        $this->sessionService->assertOwnedBy($oemWarrantyLookupSession, $request->user());
        $this->sessionService->cancel($oemWarrantyLookupSession);

        return response()->json(['ok' => true]);
    }

    public function confirm(Request $request, Order $order, OemWarrantyLookupSession $oemWarrantyLookupSession): JsonResponse
    {
        $this->authorize('checkOemWarranty', $order);
        $this->assertSessionForOrder($oemWarrantyLookupSession, $order);
        $this->sessionService->assertOwnedBy($oemWarrantyLookupSession, $request->user());

        $check = $this->checkService->confirmSession($oemWarrantyLookupSession, $request->user());

        return response()->json([
            'check_id' => $check->id,
            'provider' => $check->provider->value,
            'outcome' => $check->outcome->value,
        ]);
    }

    public function manual(Request $request, Order $order): JsonResponse
    {
        $this->authorize('checkOemWarranty', $order);

        $validated = $request->validate([
            'provider' => ['required', Rule::enum(OemWarrantyProvider::class)],
            'outcome' => ['required', 'string'],
            'rows' => ['nullable', 'array'],
        ]);

        $check = $this->checkService->confirmManual(
            $order,
            $request->user(),
            OemWarrantyProvider::from($validated['provider']),
            $validated,
        );

        return response()->json([
            'check_id' => $check->id,
            'provider' => $check->provider->value,
            'outcome' => $check->outcome->value,
        ]);
    }

    private function assertSessionForOrder(OemWarrantyLookupSession $session, Order $order): void
    {
        if ((int) $session->order_id !== (int) $order->id) {
            throw ValidationException::withMessages([
                'session' => 'Lookup session does not belong to this order.',
            ]);
        }
    }
}
