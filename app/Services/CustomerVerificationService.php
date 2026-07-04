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
        return match ($this->searchService->identityTypeForOrder($order)) {
            CustomerIdentityType::CashfreeVerified => true,
            CustomerIdentityType::Legacy => $this->isLegacyVerified($order),
            CustomerIdentityType::NewContact => false,
        };
    }

    public function requiresLegacyVerification(Order $order): bool
    {
        return $this->searchService->identityTypeForOrder($order) === CustomerIdentityType::Legacy
            && ! $this->isLegacyVerified($order);
    }

    public function assertCanCompleteService(Order $order, User $actor): void
    {
        $identityType = $this->searchService->identityTypeForOrder($order);

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

    public function isLegacyVerified(Order $order): bool
    {
        return AuditLog::query()
            ->where('auditable_type', $order->getMorphClass())
            ->where('auditable_id', $order->id)
            ->where('event', 'legacy.verification_completed')
            ->exists();
    }
}
