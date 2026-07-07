<?php

namespace App\Services;

use App\Enums\CustomerIdentityType;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CustomerVerificationService
{
    public function __construct(
        private readonly CustomerIntakeSearchService $searchService,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function canCompleteService(Order $order): bool
    {
        if ($order->isLegacyImported()) {
            return $this->isLegacyImportFulfillmentVerified($order);
        }

        return match ($this->searchService->identityTypeForOrder($order)) {
            CustomerIdentityType::CashfreeVerified => true,
            CustomerIdentityType::Legacy => $this->isLegacyVerified($order),
            CustomerIdentityType::NewContact => false,
        };
    }

    public function requiresLegacyVerification(Order $order): bool
    {
        if ($this->requiresLegacyImportFulfillmentVerification($order)) {
            return true;
        }

        if ($order->isLegacyImported()) {
            return false;
        }

        return $this->searchService->identityTypeForOrder($order) === CustomerIdentityType::Legacy
            && ! $this->isLegacyVerified($order);
    }

    public function requiresLegacyImportFulfillmentVerification(Order $order): bool
    {
        return $order->isLegacyImported()
            && ! $this->isLegacyImportFulfillmentVerified($order);
    }

    public function legacyVerificationMode(Order $order): string
    {
        return $order->isLegacyImported() ? 'imported' : 'customer';
    }

    public function assertCanCompleteService(Order $order, User $actor): void
    {
        $identityType = $this->searchService->identityTypeForOrder($order);

        if ($order->isLegacyImported()) {
            if ($this->isLegacyImportFulfillmentVerified($order)) {
                return;
            }

            $this->auditLogService->log(
                userId: $actor->id,
                event: 'transaction.assignment_blocked',
                auditable: $order,
                newValues: [
                    'identity_type' => $identityType->value,
                    'reason' => 'legacy_import_fulfillment_verification_required',
                ],
            );

            throw ValidationException::withMessages([
                'transaction_id' => 'Legacy imported order verification required before completing service.',
            ]);
        }

        if ($identityType === CustomerIdentityType::CashfreeVerified) {
            return;
        }

        if ($identityType === CustomerIdentityType::Legacy && $this->isLegacyVerified($order)) {
            return;
        }

        $this->auditLogService->log(
            userId: $actor->id,
            event: 'transaction.assignment_blocked',
            auditable: $order,
            newValues: [
                'identity_type' => $identityType->value,
                'reason' => 'customer_verification_required',
            ],
        );

        throw ValidationException::withMessages([
            'transaction_id' => 'Customer verification required before completing service.',
        ]);
    }

    public function confirmLegacyVerification(Order $order, User $actor, ?Request $request = null): void
    {
        if ($order->isLegacyImported()) {
            $this->confirmLegacyImportFulfillmentVerification($order, $actor, $request);

            return;
        }

        if ($this->searchService->identityTypeForOrder($order) !== CustomerIdentityType::Legacy) {
            throw ValidationException::withMessages([
                'order' => 'Legacy verification is only required for legacy customers.',
            ]);
        }

        if ($this->isLegacyVerified($order)) {
            return;
        }

        $this->auditLogService->log(
            userId: $actor->id,
            event: 'legacy.verification_completed',
            auditable: $order,
            newValues: [
                'customer_matched' => true,
                'device_verified' => true,
                'service_eligibility_checked' => true,
            ],
            request: $request,
        );
    }

    public function confirmLegacyImportFulfillmentVerification(Order $order, User $actor, ?Request $request = null): void
    {
        if (! $order->isLegacyImported()) {
            throw ValidationException::withMessages([
                'order' => 'Legacy import fulfillment verification is only required for imported orders.',
            ]);
        }

        if ($this->isLegacyImportFulfillmentVerified($order)) {
            return;
        }

        $this->auditLogService->log(
            userId: $actor->id,
            event: 'legacy_order.verified_for_fulfillment',
            auditable: $order,
            newValues: [
                'legacy_source' => $order->legacy_source,
                'verified_by_user_id' => $actor->id,
            ],
            request: $request,
        );
    }

    public function isLegacyVerified(Order $order): bool
    {
        return AuditLog::query()
            ->where('auditable_type', $order->getMorphClass())
            ->where('auditable_id', $order->id)
            ->where('event', 'legacy.verification_completed')
            ->exists();
    }

    public function isLegacyImportFulfillmentVerified(Order $order): bool
    {
        return AuditLog::query()
            ->where('auditable_type', $order->getMorphClass())
            ->where('auditable_id', $order->id)
            ->where('event', 'legacy_order.verified_for_fulfillment')
            ->exists();
    }
}
