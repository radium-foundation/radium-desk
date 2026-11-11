<?php

namespace App\Services\OemWarranty;

use App\Enums\OemWarrantyCaptureMethod;
use App\Enums\OemWarrantyLookupOutcome;
use App\Enums\OemWarrantyLookupSessionStatus;
use App\Enums\OemWarrantyProvider;
use App\Models\OemWarrantyCheck;
use App\Models\OemWarrantyLookupSession;
use App\Models\Order;
use App\Models\User;
use App\Services\AuditLogService;
use App\Support\Orders\OrderSerialOwnership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class OemWarrantyCheckService
{
    public function __construct(
        private readonly OemWarrantyResultValidator $resultValidator,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function confirmSession(OemWarrantyLookupSession $session, User $user): OemWarrantyCheck
    {
        if ($session->consumed_at !== null || $session->status === OemWarrantyLookupSessionStatus::Consumed) {
            throw ValidationException::withMessages([
                'session' => 'This lookup session was already confirmed.',
            ]);
        }

        if ($session->status !== OemWarrantyLookupSessionStatus::DraftReady || ! is_array($session->draft_payload)) {
            throw ValidationException::withMessages([
                'session' => 'No draft is ready for confirmation.',
            ]);
        }

        return DB::transaction(function () use ($session, $user) {
            $payload = $session->draft_payload;

            $captureMethod = $payload['capture_method'] ?? OemWarrantyCaptureMethod::ExtensionDom->value;
            if ($captureMethod instanceof OemWarrantyCaptureMethod) {
                $captureEnum = $captureMethod;
            } else {
                $captureEnum = OemWarrantyCaptureMethod::from((string) $captureMethod);
            }

            if ($captureEnum === OemWarrantyCaptureMethod::ExtensionDom && ! config('oem_warranty.extension_dom_enabled')) {
                throw ValidationException::withMessages([
                    'session' => 'Automated browser capture is disabled. Save results using manual entry.',
                ]);
            }

            $check = $this->persistCheck(
                order: $session->order()->firstOrFail(),
                user: $user,
                provider: $session->provider,
                serial: $session->serial_number,
                validated: $payload,
                captureMethod: $captureEnum,
                sessionId: $session->id,
            );

            $session->status = OemWarrantyLookupSessionStatus::Consumed;
            $session->consumed_at = now();
            $session->save();

            return $check;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function confirmManual(Order $order, User $user, OemWarrantyProvider $provider, array $payload): OemWarrantyCheck
    {
        $serial = OrderSerialOwnership::normalizeSerial($order->serial_number);
        if ($serial === null) {
            throw ValidationException::withMessages([
                'serial_number' => 'Assign a serial number before saving manual OEM results.',
            ]);
        }

        $validated = $this->resultValidator->validateDraft(
            $provider,
            $serial,
            OemWarrantyCaptureMethod::ManualEntry,
            $payload,
        );

        return DB::transaction(fn () => $this->persistCheck(
            order: $order,
            user: $user,
            provider: $provider,
            serial: $serial,
            validated: $validated,
            captureMethod: OemWarrantyCaptureMethod::ManualEntry,
            sessionId: null,
        ));
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function persistCheck(
        Order $order,
        User $user,
        OemWarrantyProvider $provider,
        string $serial,
        array $validated,
        OemWarrantyCaptureMethod $captureMethod,
        ?int $sessionId,
    ): OemWarrantyCheck {
        $outcome = $validated['outcome'] instanceof OemWarrantyLookupOutcome
            ? $validated['outcome']
            : OemWarrantyLookupOutcome::from((string) $validated['outcome']);

        $previousCurrent = OemWarrantyCheck::query()
            ->where('order_id', $order->id)
            ->where('provider', $provider->value)
            ->where('is_current', true)
            ->lockForUpdate()
            ->get();

        if ($outcome === OemWarrantyLookupOutcome::Error && $previousCurrent->isNotEmpty()) {
            $validator = Validator::make([], []);
            $validator->errors()->add('outcome', 'Failed lookups cannot replace an existing saved OEM record.');
            throw new ValidationException($validator);
        }

        foreach ($previousCurrent as $existing) {
            $existing->is_current = false;
            $existing->save();
        }

        $check = OemWarrantyCheck::query()->create([
            'order_id' => $order->id,
            'oem_warranty_lookup_session_id' => $sessionId,
            'provider' => $provider,
            'serial_number' => $serial,
            'outcome' => $outcome,
            'capture_method' => $captureMethod,
            'verified_by_user_id' => $user->id,
            'checked_at' => now(),
            'warranty_expiry_date' => $validated['warranty_expiry_date'] ?? null,
            'rd_expiry_date' => $validated['rd_expiry_date'] ?? null,
            'mantratec_model' => $validated['mantratec_model'] ?? null,
            'morpho_order_number' => $validated['morpho_order_number'] ?? null,
            'morpho_whitelisting_date' => $validated['morpho_whitelisting_date'] ?? null,
            'morpho_expiry_date' => $validated['morpho_expiry_date'] ?? null,
            'morpho_validity_years' => $validated['morpho_validity_years'] ?? null,
            'provider_payload' => $validated['provider_payload'] ?? null,
            'is_current' => true,
        ]);

        $this->auditLogService->log(
            $user->id,
            'oem_warranty.check.confirmed',
            $order,
            oldValues: null,
            newValues: [
                'provider' => $provider->value,
                'outcome' => $outcome->value,
                'capture_method' => $captureMethod->value,
                'oem_warranty_check_id' => $check->id,
            ],
        );

        return $check;
    }
}
