<?php

namespace App\Services\DashboardOemHandoff;

use App\Enums\DashboardOemHandoffSessionStatus;
use App\Enums\OemHandoffExpiryPersistStatus;
use App\Models\DashboardOemHandoffSession;
use App\Models\ServiceReferenceOemSubscriptionExpiry;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\OrderTransactionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OemHandoffAdministratorCompletionService
{
    public const AUDIT_EVENT_COMPLETE = 'oem_handoff.administrator_completed';

    public function __construct(
        private readonly DashboardOemHandoffSessionService $sessionService,
        private readonly OrderTransactionService $orderTransactionService,
        private readonly ServiceReferenceOemSubscriptionExpiryPersistenceService $expiryPersistence,
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * @return array{session: DashboardOemHandoffSession, order_id: int, service_reference: string, subscription_expiry_date: string}
     */
    public function complete(
        DashboardOemHandoffSession $session,
        User $actor,
        string $serviceReference,
        string $subscriptionExpiryDate,
    ): array {
        $this->sessionService->assertOwnedBy($session, $actor);
        $this->sessionService->assertForIncident($session, $session->incident);

        if ($session->status !== DashboardOemHandoffSessionStatus::DraftReady) {
            throw ValidationException::withMessages([
                'session' => ['No captured OEM result is awaiting administrator completion.'],
            ]);
        }

        if ($session->draft_payload === null) {
            throw ValidationException::withMessages([
                'session' => ['Captured OEM evidence is missing.'],
            ]);
        }

        $serviceReference = trim($serviceReference);
        $subscriptionExpiryDate = trim($subscriptionExpiryDate);

        $session->loadMissing('incident.order');
        $order = $session->incident?->order;
        if ($order === null) {
            throw ValidationException::withMessages([
                'order' => ['This service case has no order.'],
            ]);
        }

        $this->assertExpiryOverwriteSafe($session, $serviceReference, $subscriptionExpiryDate);

        return DB::transaction(function () use ($session, $actor, $serviceReference, $subscriptionExpiryDate, $order): array {
            $locked = $session->newQuery()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== DashboardOemHandoffSessionStatus::DraftReady) {
                throw ValidationException::withMessages([
                    'session' => ['This OEM handoff was already completed or closed.'],
                ]);
            }

            $session->confirmed_at = now();
            $session->confirmed_by_user_id = $actor->id;
            $session->confirmed_payload = $session->draft_payload;
            $session->draft_reviewed_at = now();
            $session->status = DashboardOemHandoffSessionStatus::Confirmed;
            $session->save();

            $freshOrder = $this->orderTransactionService->assignTransactionId(
                order: $order,
                transactionId: $serviceReference,
                actor: $actor,
            );

            $expiryResult = $this->expiryPersistence->persistAdministratorProvidedExpiry(
                $session,
                $actor,
                $serviceReference,
                $subscriptionExpiryDate,
            );

            $this->auditLogService->log(
                userId: $actor->id,
                event: self::AUDIT_EVENT_COMPLETE,
                auditable: $session,
                oldValues: ['status' => DashboardOemHandoffSessionStatus::DraftReady->value],
                newValues: [
                    'status' => DashboardOemHandoffSessionStatus::Confirmed->value,
                    'service_reference' => $serviceReference,
                    'subscription_expiry_date' => $subscriptionExpiryDate,
                    'oem_expiry_persist_status' => $expiryResult['status']->value,
                ],
            );

            return [
                'session' => $session->fresh(),
                'order_id' => $freshOrder->id,
                'service_reference' => $serviceReference,
                'subscription_expiry_date' => $subscriptionExpiryDate,
            ];
        });
    }

    private function assertExpiryOverwriteSafe(
        DashboardOemHandoffSession $session,
        string $serviceReference,
        string $subscriptionExpiryDate,
    ): void {
        /** @var ServiceReferenceOemSubscriptionExpiry|null $existing */
        $existing = ServiceReferenceOemSubscriptionExpiry::query()
            ->where('service_reference', $serviceReference)
            ->first();

        if ($existing === null) {
            return;
        }

        $sameDate = $existing->subscription_expiry_date->toDateString() === $subscriptionExpiryDate;
        $sameSession = (int) $existing->source_oem_handoff_session_id === (int) $session->id;

        if ($sameDate && $sameSession) {
            return;
        }

        if ($sameDate) {
            return;
        }

        throw ValidationException::withMessages([
            'subscription_expiry_date' => [
                'An expiry date is already recorded for this service reference. Update it through the authorized service-reference workflow.',
            ],
        ]);
    }
}