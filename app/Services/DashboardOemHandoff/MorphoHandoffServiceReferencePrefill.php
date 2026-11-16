<?php

namespace App\Services\DashboardOemHandoff;

use App\Models\DashboardOemHandoffSession;
use App\Support\Oem\MorphoWhitelistQualification;
use Illuminate\Support\Str;

final class MorphoHandoffServiceReferencePrefill
{
    /**
     * @param  array<string, mixed>  $validatedDraft
     * @return array<string, mixed>
     */
    public function build(DashboardOemHandoffSession $session, array $validatedDraft): array
    {
        $qualification = (string) ($validatedDraft['whitelist_qualification'] ?? MorphoWhitelistQualification::INCONCLUSIVE);
        $outcome = (string) ($validatedDraft['outcome'] ?? 'inconclusive');
        $fields = is_array($validatedDraft['fields'] ?? null) ? $validatedDraft['fields'] : [];

        $rejectionReason = null;
        $eligible = MorphoWhitelistQualification::isPopulationEligible($qualification, $outcome);

        if ($eligible && ! $this->serialMatchesSession($session, $validatedDraft)) {
            $eligible = false;
            $rejectionReason = 'OEM serial does not match this Desk session.';
        }

        if ($eligible && ! $this->deviceModelMatches($session, $fields)) {
            $eligible = false;
            $rejectionReason = 'OEM device type does not match this service case model.';
        }

        if (! $eligible && $rejectionReason === null) {
            $rejectionReason = $this->ineligibilityReason($qualification, $outcome);
        }

        return [
            'eligible' => $eligible,
            'whitelist_qualification' => $qualification,
            'session_public_id' => $session->public_id,
            'incident_id' => $session->incident_id,
            'serial_number' => $validatedDraft['serial_number'] ?? $session->serial_number,
            'oem_review' => [
                'device_validity' => $fields['device_validity'] ?? null,
                'whitelist_status' => $fields['whitelist_status'] ?? null,
                'whitelist_date' => $fields['whitelist_date'] ?? null,
                'expiry_date' => $fields['expiry_date'] ?? null,
                'device_type' => $fields['device_type'] ?? null,
                'validity_years' => $fields['validity_years'] ?? null,
            ],
            'suggested_transaction_id' => null,
            'rejection_reason' => $rejectionReason,
        ];
    }

    /**
     * @param  array<string, mixed>  $validatedDraft
     */
    private function serialMatchesSession(DashboardOemHandoffSession $session, array $validatedDraft): bool
    {
        $sessionSerial = Str::upper(trim((string) $session->serial_number));
        $draftSerial = Str::upper(trim((string) ($validatedDraft['serial_number'] ?? '')));

        return $sessionSerial !== '' && $sessionSerial === $draftSerial;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function deviceModelMatches(DashboardOemHandoffSession $session, array $fields): bool
    {
        $expected = trim((string) $session->device_model);
        $capturedType = trim((string) ($fields['device_type'] ?? ''));

        if ($expected === '' || $capturedType === '') {
            return true;
        }

        $expectedNorm = Str::upper(preg_replace('/\s+/', '', $expected) ?? $expected);
        $capturedNorm = Str::upper(preg_replace('/\s+/', '', $capturedType) ?? $capturedType);

        if ($expectedNorm === $capturedNorm) {
            return true;
        }

        return str_contains($capturedNorm, $expectedNorm) || str_contains($expectedNorm, $capturedNorm);
    }

    private function ineligibilityReason(string $qualification, string $outcome): string
    {
        return match (true) {
            $qualification === MorphoWhitelistQualification::VALID_NOT_WHITELISTED => 'Device is valid on Morpho but is not already whitelisted.',
            $qualification === MorphoWhitelistQualification::INVALID_DEVICE => 'Morpho reported an invalid device — not eligible for whitelist prefill.',
            $qualification === MorphoWhitelistQualification::AMBIGUOUS => 'Morpho whitelist status is ambiguous — manual review required.',
            $outcome === 'inconclusive' => 'Morpho did not return a conclusive result.',
            default => 'Only an explicit already-whitelisted Morpho result can prefill the service reference workflow.',
        };
    }
}