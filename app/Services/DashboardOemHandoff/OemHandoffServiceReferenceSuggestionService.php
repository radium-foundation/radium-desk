<?php

namespace App\Services\DashboardOemHandoff;

use App\Models\DashboardOemHandoffSession;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;

final class OemHandoffServiceReferenceSuggestionService
{
    public const AUDIT_EVENT_GENERATED = 'service_reference.suggestion_generated';

    public function __construct(
        private readonly OemHandoffSubscriptionExpiryExtractor $expiryExtractor,
        private readonly RdExpiryServiceReferenceSequenceAllocator $sequenceAllocator,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function generateForConfirmedSession(DashboardOemHandoffSession $session, User $actor): void
    {
        if (! $this->suggestionsEnabled()) {
            return;
        }

        if (filled($session->suggested_service_reference)) {
            return;
        }

        $payload = $session->confirmed_payload;
        if (! is_array($payload)) {
            return;
        }

        $outcome = (string) ($payload['outcome'] ?? 'inconclusive');
        if ($outcome !== 'found') {
            return;
        }

        $expiry = $this->expiryExtractor->extract($session->oem, $payload);
        if ($expiry === null) {
            return;
        }

        $session->loadMissing('incident.order');
        $order = $session->incident?->order;
        if ($order === null) {
            return;
        }

        if (filled($order->transaction_id)) {
            return;
        }

        DB::transaction(function () use ($session, $actor, $expiry): void {
            $session->refresh();
            if (filled($session->suggested_service_reference)) {
                return;
            }

            $allocated = $this->sequenceAllocator->allocate($expiry, $session->id);

            $session->suggested_service_reference = $allocated['reference'];
            $session->suggested_service_reference_expiry_date = $allocated['expiry_date'];
            $session->suggested_service_reference_sequence = $allocated['sequence'];
            $session->suggested_service_reference_generated_at = now();
            $session->save();

            $this->auditLogService->log(
                userId: $actor->id,
                event: self::AUDIT_EVENT_GENERATED,
                auditable: $session,
                oldValues: null,
                newValues: [
                    'suggested_service_reference' => $allocated['reference'],
                    'suggested_service_reference_expiry_date' => $allocated['expiry_date'],
                    'suggested_service_reference_sequence' => $allocated['sequence'],
                    'incident_id' => $session->incident_id,
                    'oem' => $session->oem,
                ],
            );
        });
    }

    private function suggestionsEnabled(): bool
    {
        return (bool) config('dashboard_oem_handoff.service_reference_suggestion_enabled', false);
    }
}