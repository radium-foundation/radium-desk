<?php

namespace App\Services\DashboardOemHandoff;

use App\Models\DashboardOemHandoffSession;

final class OemHandoffServiceReferencePrefillPresenter
{
    public function __construct(
        private readonly MorphoHandoffServiceReferencePrefill $morphoPrefill,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function build(DashboardOemHandoffSession $session): ?array
    {
        $draft = $session->confirmed_payload ?? $session->draft_payload;
        if (! is_array($draft)) {
            return null;
        }

        $suggestion = $this->suggestionEnvelope($session);

        if ($session->oem === 'morpho') {
            $base = $this->morphoPrefill->build($session, $draft);
            $eligible = ($base['eligible'] ?? false) || $suggestion['suggestion_generated'] === true;

            return array_merge($base, $suggestion, ['eligible' => $eligible]);
        }

        if ($session->oem === 'mantra') {
            return array_merge([
                'eligible' => $suggestion['suggestion_generated'] === true,
                'whitelist_qualification' => null,
                'session_public_id' => $session->public_id,
                'incident_id' => $session->incident_id,
                'serial_number' => $draft['serial_number'] ?? $session->serial_number,
                'oem_review' => [
                    'rd_expiry_date' => $draft['rd_expiry_date'] ?? null,
                    'warranty_expiry_date' => $draft['warranty_expiry_date'] ?? null,
                    'model' => $draft['model'] ?? null,
                ],
                'rejection_reason' => $suggestion['suggestion_generated']
                    ? null
                    : 'Mantra OEM result has no service reference suggestion.',
            ], $suggestion);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function suggestionEnvelope(DashboardOemHandoffSession $session): array
    {
        $suggested = trim((string) ($session->suggested_service_reference ?? ''));

        return [
            'suggested_transaction_id' => $suggested !== '' ? $suggested : null,
            'suggestion_generated' => $suggested !== '',
            'suggested_expiry_date' => $session->suggested_service_reference_expiry_date?->toDateString(),
            'suggestion_requires_manual_save' => true,
        ];
    }
}