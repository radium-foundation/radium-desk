<?php

namespace App\Services\DashboardOemHandoff;

use App\Enums\DashboardOemHandoffSessionStatus;
use App\Enums\OemHandoffExpiryPersistStatus;
use App\Models\DashboardOemHandoffSession;
use App\Models\Order;
use App\Models\ServiceReferenceOemSubscriptionExpiry;
use App\Models\User;
use App\Services\AuditLogService;
use App\Support\Orders\OrderSerialOwnership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ServiceReferenceOemSubscriptionExpiryPersistenceService
{
    public const AUDIT_EVENT_UPDATED = 'service_reference.oem_subscription_expiry_updated';

    public function __construct(
        private readonly OemHandoffSubscriptionExpiryExtractor $extractor,
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * @return array{status: OemHandoffExpiryPersistStatus, service_reference: ?string, subscription_expiry_date: ?string}
     */
    public function applyFromConfirmedSession(DashboardOemHandoffSession $session, User $actor): array
    {
        if (! $this->persistenceEnabled()) {
            return $this->markSession($session, OemHandoffExpiryPersistStatus::SkippedDisabled, null, null);
        }

        $payload = $session->confirmed_payload;
        if (! is_array($payload)) {
            return $this->markSession($session, OemHandoffExpiryPersistStatus::SkippedNoExpiry, null, null);
        }

        $expiry = $this->extractor->extract($session->oem, $payload);
        if ($expiry === null) {
            return $this->markSession($session, OemHandoffExpiryPersistStatus::SkippedNoExpiry, null, null);
        }

        $order = $session->incident?->order;
        if ($order === null) {
            return $this->markSession(
                $session,
                OemHandoffExpiryPersistStatus::QuarantinedInvalidServiceReference,
                null,
                'Service case has no order.',
            );
        }

        if (! $this->serialMatchesOrder($session, $order)) {
            return $this->markSession(
                $session,
                OemHandoffExpiryPersistStatus::QuarantinedInvalidServiceReference,
                null,
                'Handoff serial does not match order serial.',
            );
        }

        $serviceReference = trim((string) ($order->transaction_id ?? ''));
        if ($serviceReference === '') {
            return $this->markSession(
                $session,
                OemHandoffExpiryPersistStatus::QuarantinedMissingServiceReference,
                null,
                null,
            );
        }

        if (! $this->serviceReferenceExists($serviceReference)) {
            return $this->markSession(
                $session,
                OemHandoffExpiryPersistStatus::QuarantinedInvalidServiceReference,
                null,
                'Service reference is not recorded on any order.',
            );
        }

        return $this->persistExpiry($session, $actor, $serviceReference, $expiry);
    }

    /**
     * Administrator-provided RD subscription expiry (not OEM auto-trust). Runs regardless of expiry_persist feature flag.
     *
     * @return array{status: OemHandoffExpiryPersistStatus, service_reference: ?string, subscription_expiry_date: ?string}
     */
    public function persistAdministratorProvidedExpiry(
        DashboardOemHandoffSession $session,
        User $actor,
        string $serviceReference,
        string $expiryYmd,
    ): array {
        return $this->persistExpiry($session, $actor, $serviceReference, $expiryYmd);
    }

    public function flushQuarantinedSessionsForOrder(Order $order, User $actor): void
    {
        if (! $this->persistenceEnabled()) {
            return;
        }

        $serviceReference = trim((string) ($order->transaction_id ?? ''));
        if ($serviceReference === '') {
            return;
        }

        $sessions = DashboardOemHandoffSession::query()
            ->whereHas('incident', fn ($q) => $q->where('order_record_id', $order->id))
            ->where('status', DashboardOemHandoffSessionStatus::Confirmed->value)
            ->where('oem_expiry_persist_status', OemHandoffExpiryPersistStatus::QuarantinedMissingServiceReference->value)
            ->orderBy('confirmed_at')
            ->get();

        foreach ($sessions as $session) {
            $session->loadMissing('incident.order');
            $this->applyFromConfirmedSession($session, $actor);
        }
    }

    public function findForServiceReference(?string $serviceReference): ?ServiceReferenceOemSubscriptionExpiry
    {
        $normalized = trim((string) ($serviceReference ?? ''));
        if ($normalized === '') {
            return null;
        }

        return ServiceReferenceOemSubscriptionExpiry::query()
            ->where('service_reference', $normalized)
            ->first();
    }

    private function persistenceEnabled(): bool
    {
        return (bool) config('dashboard_oem_handoff.expiry_persist_enabled', false);
    }

    private function serviceReferenceExists(string $serviceReference): bool
    {
        return Order::query()
            ->where('transaction_id', $serviceReference)
            ->exists();
    }

    private function serialMatchesOrder(DashboardOemHandoffSession $session, Order $order): bool
    {
        $sessionSerial = OrderSerialOwnership::normalizeSerial($session->serial_number);
        $orderSerial = OrderSerialOwnership::normalizeSerial($order->serial_number);

        if ($sessionSerial === null || $orderSerial === null) {
            return false;
        }

        return Str::upper($sessionSerial) === Str::upper($orderSerial);
    }

    /**
     * @return array{status: OemHandoffExpiryPersistStatus, service_reference: ?string, subscription_expiry_date: ?string}
     */
    private function persistExpiry(
        DashboardOemHandoffSession $session,
        User $actor,
        string $serviceReference,
        string $expiry,
    ): array {
        return DB::transaction(function () use ($session, $actor, $serviceReference, $expiry): array {
            /** @var ServiceReferenceOemSubscriptionExpiry|null $existing */
            $existing = ServiceReferenceOemSubscriptionExpiry::query()
                ->where('service_reference', $serviceReference)
                ->lockForUpdate()
                ->first();

            if ($existing !== null
                && $existing->source_oem_handoff_session_id === $session->id
                && $existing->subscription_expiry_date->toDateString() === $expiry) {
                $this->markSessionOnly($session, OemHandoffExpiryPersistStatus::PersistedDuplicate, $serviceReference);

                return [
                    'status' => OemHandoffExpiryPersistStatus::PersistedDuplicate,
                    'service_reference' => $serviceReference,
                    'subscription_expiry_date' => $expiry,
                ];
            }

            $status = $existing === null
                ? OemHandoffExpiryPersistStatus::Persisted
                : OemHandoffExpiryPersistStatus::Updated;

            $oldValues = $existing !== null ? [
                'service_reference' => $existing->service_reference,
                'oem' => $existing->oem,
                'subscription_expiry_date' => $existing->subscription_expiry_date->toDateString(),
            ] : null;

            $record = ServiceReferenceOemSubscriptionExpiry::query()->updateOrCreate(
                ['service_reference' => $serviceReference],
                [
                    'oem' => $session->oem,
                    'subscription_expiry_date' => $expiry,
                    'source_oem_handoff_session_id' => $session->id,
                    'updated_by_user_id' => $actor->id,
                ],
            );

            $this->auditLogService->log(
                userId: $actor->id,
                event: self::AUDIT_EVENT_UPDATED,
                auditable: $record,
                oldValues: $oldValues,
                newValues: [
                    'service_reference' => $record->service_reference,
                    'oem' => $record->oem,
                    'subscription_expiry_date' => $record->subscription_expiry_date->toDateString(),
                    'source_oem_handoff_session_id' => $record->source_oem_handoff_session_id,
                ],
            );

            $this->markSessionOnly($session, $status, $serviceReference);

            return [
                'status' => $status,
                'service_reference' => $serviceReference,
                'subscription_expiry_date' => $expiry,
            ];
        });
    }

    /**
     * @return array{status: OemHandoffExpiryPersistStatus, service_reference: ?string, subscription_expiry_date: ?string}
     */
    private function markSession(
        DashboardOemHandoffSession $session,
        OemHandoffExpiryPersistStatus $status,
        ?string $serviceReference,
        ?string $detail,
    ): array {
        $this->markSessionOnly($session, $status, $serviceReference, $detail);

        return [
            'status' => $status,
            'service_reference' => $serviceReference,
            'subscription_expiry_date' => null,
        ];
    }

    private function markSessionOnly(
        DashboardOemHandoffSession $session,
        OemHandoffExpiryPersistStatus $status,
        ?string $serviceReference,
        ?string $detail = null,
    ): void {
        $session->oem_expiry_persist_status = $status->value;
        if ($detail !== null) {
            $session->oem_expiry_persist_detail = Str::limit($detail, 255, '');
        } elseif ($status === OemHandoffExpiryPersistStatus::QuarantinedMissingServiceReference) {
            $session->oem_expiry_persist_detail = 'Assign a service reference before OEM expiry can be saved.';
        } elseif (! $status->isQuarantined()) {
            $session->oem_expiry_persist_detail = $serviceReference !== null
                ? "Stored against {$serviceReference}."
                : null;
        }
        $session->save();
    }
}
