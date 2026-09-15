<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\EInvoiceIssuanceKind;
use App\Enums\EInvoiceIssuancePolicyMode;
use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\Data\EInvoiceIssuancePolicyResult;

/**
 * Rollout permission for automatic GENERATE. Not payload/IsServc.
 * Default HARDWARE_ONLY. ALL_ELIGIBLE_B2B via STATUTORY_EINVOICE_ISSUANCE_POLICY.
 * Invalid config fails closed to hardware_only. Does not requeue skipped rows.
 */
final class EInvoiceIssuancePolicy
{
    public const SKIP_SERVICE = 'issuance_policy_service_excluded';

    public const SKIP_MIXED = 'issuance_policy_mixed_lines';

    public const SKIP_UNKNOWN = 'issuance_policy_unknown';

    public function __construct(
        private readonly EInvoiceIssuanceClassifier $classifier,
    ) {}

    public function mode(): EInvoiceIssuancePolicyMode
    {
        $raw = config('statutory_invoices.einvoice.issuance_policy', EInvoiceIssuancePolicyMode::HardwareOnly->value);
        if (! is_string($raw) || $raw === '') {
            return EInvoiceIssuancePolicyMode::HardwareOnly;
        }

        return EInvoiceIssuancePolicyMode::tryFrom(trim($raw))
            ?? EInvoiceIssuancePolicyMode::HardwareOnly;
    }

    public function evaluate(StatutoryInvoice $invoice): EInvoiceIssuancePolicyResult
    {
        $kind = $this->classifier->classify($invoice);

        if ($kind === EInvoiceIssuanceKind::Unknown) {
            return new EInvoiceIssuancePolicyResult(false, self::SKIP_UNKNOWN);
        }
        if ($kind === EInvoiceIssuanceKind::Mixed) {
            return new EInvoiceIssuancePolicyResult(false, self::SKIP_MIXED);
        }

        if ($this->mode() === EInvoiceIssuancePolicyMode::AllEligibleB2b) {
            return new EInvoiceIssuancePolicyResult(true, 'b2b_eligible');
        }

        if ($kind === EInvoiceIssuanceKind::Hardware) {
            return new EInvoiceIssuancePolicyResult(true, 'b2b_eligible');
        }

        return new EInvoiceIssuancePolicyResult(false, self::SKIP_SERVICE);
    }
}
