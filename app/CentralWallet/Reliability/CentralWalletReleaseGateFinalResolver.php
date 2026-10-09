<?php

namespace App\CentralWallet\Reliability;

/**
 * Aggregates release-gate section outcomes into a final status.
 *
 * FAIL and BLOCKED always block. WARN blocks only when marked blocking (default true).
 * Documented non-blocking states (§ F provider customer_display, account_link NON-BLOCKING) do not block PASS.
 */
final class CentralWalletReleaseGateFinalResolver
{
    public function __construct(
        private readonly CentralWalletReleaseGateConfiguration $configuration,
    ) {}

    /**
     * @param  array<string, array<string, mixed>>  $sections
     * @return array<string, array<string, mixed>>
     */
    public function enrichSections(array $sections): array
    {
        $enriched = [];

        foreach ($sections as $name => $section) {
            $enriched[$name] = $this->enrichSection($name, $section);
        }

        return $enriched;
    }

    /**
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    public function enrichSection(string $name, array $section): array
    {
        $blocking = $this->sectionBlocksFinal($name, $section);
        $section['blocking'] = $blocking;

        if (! $blocking && ($section['status'] ?? '') === 'WARN' && ! isset($section['reason'])) {
            $section['reason'] = $this->nonBlockingWarnReason($name, $section);
        }

        if (($section['status'] ?? '') === 'NON-BLOCKING' && ! isset($section['reason'])) {
            $section['reason'] = 'Documented non-blocking variance; does not block aggregate PASS.';
        }

        return $section;
    }

    /**
     * @param  array<string, array<string, mixed>>  $sections
     * @return array{status: string, message: string}
     */
    public function resolve(array $sections): array
    {
        foreach ($sections as $section) {
            if (($section['status'] ?? '') === 'BLOCKED') {
                return [
                    'status' => 'BLOCKED',
                    'message' => 'BLOCKED — FIXTURE OR RECONCILIATION NOT CONFIGURED',
                ];
            }
        }

        foreach ($sections as $section) {
            if (($section['status'] ?? '') === 'FAIL') {
                return ['status' => 'FAIL', 'message' => ''];
            }
        }

        foreach ($sections as $name => $section) {
            if (($section['status'] ?? '') === 'WARN' && $this->sectionBlocksFinal($name, $section)) {
                return ['status' => 'WARN', 'message' => ''];
            }
        }

        return ['status' => 'PASS', 'message' => ''];
    }

    /**
     * @param  array<string, mixed>  $section
     */
    public function sectionBlocksFinal(string $name, array $section): bool
    {
        $status = (string) ($section['status'] ?? 'FAIL');

        if (in_array($status, ['PASS', 'NON-BLOCKING'], true)) {
            return false;
        }

        if ($status === 'FAIL' || $status === 'BLOCKED') {
            return true;
        }

        if ($status !== 'WARN') {
            return $status !== 'PASS';
        }

        if (array_key_exists('blocking', $section)) {
            return (bool) $section['blocking'];
        }

        if ($name === 'customer_display' && $this->isProviderLaneCustomerDisplayWarn($section)) {
            return false;
        }

        if ($name === 'account_link_variance' && $this->isAccountLinkPreDeferralWarn($section)) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $section
     */
    /**
     * @param  array<string, mixed>  $section
     */
    private function isAccountLinkPreDeferralWarn(array $section): bool
    {
        foreach ($section['checks'] ?? [] as $check) {
            if (($check['id'] ?? '') === 'account_link_reconciliation_configured'
                && ($check['result'] ?? '') === 'WARN'
                && ($check['details']['blocking'] ?? true) === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $section
     */
    private function isProviderLaneCustomerDisplayWarn(array $section): bool
    {
        if ($this->configuration->role() !== 'provider') {
            return false;
        }

        foreach ($section['checks'] ?? [] as $check) {
            if (($check['id'] ?? '') === 'customer_display_provider_lane') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $section
     */
    private function nonBlockingWarnReason(string $name, array $section): string
    {
        if ($name === 'customer_display' && $this->isProviderLaneCustomerDisplayWarn($section)) {
            return 'Customer display synthetic is N/A on Desk provider lane (§ F); probe not executed.';
        }

        foreach ($section['checks'] ?? [] as $check) {
            $message = trim((string) (($check['details']['message'] ?? '') ?: ($check['details']['reason'] ?? '')));
            if ($message !== '') {
                return $message;
            }
        }

        return 'Documented non-blocking warning.';
    }
}
