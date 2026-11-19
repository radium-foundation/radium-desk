<?php

namespace App\Services\StatutoryInvoice;

use App\Contracts\StatutoryInvoice\BuyerGstinVerificationGateway;
use App\Enums\BuyerGstVerificationStatus;
use App\Enums\CommerceInvoiceGstClassification;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderBuyerGstVerification;
use App\Services\StatutoryInvoice\Data\BuyerGstinVerificationResult;
use App\Support\Finance\GstStateCodes;
use App\Support\StatutoryInvoice\StatutoryBillingStructured;
use Illuminate\Support\Facades\DB;

final class CommerceOrderBuyerGstVerificationService
{
    public function __construct(
        private readonly BuyerGstinVerificationGateway $gateway,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('statutory_invoices.buyer_gstin_verification.enabled', false);
    }

    public function requiresProviderVerification(CommerceOrder $order): bool
    {
        return CommerceOrderBuyerGstStateAlignment::requiresProviderVerification($order, $this->isEnabled());
    }

    public function processAfterChannelIngest(CommerceOrder $order): ?CommerceOrderBuyerGstVerification
    {
        if (! $this->requiresProviderVerification($order)) {
            return null;
        }

        $fresh = $order->fresh() ?? $order;
        $this->ensureSubmittedSnapshot($fresh);

        return $this->verifyAndPersist($fresh);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function customer360Summary(CommerceOrder $order): ?array
    {
        $gstin = BuyerGstin::normalize($order->buyer_gstin);
        if ($gstin === null) {
            return null;
        }

        if (! $this->isEnabled()) {
            return [
                'scope' => 'disabled',
                'status_label' => 'Verification policy disabled',
                'reason' => null,
                'verified_at_label' => null,
            ];
        }

        if (! $this->requiresProviderVerification($order)) {
            return [
                'scope' => 'not_required',
                'status_label' => 'No verification required',
                'reason' => 'GSTIN state matches submitted billing state.',
                'verified_at_label' => null,
            ];
        }

        $record = $this->recordForOrder($order);
        if ($record === null) {
            return [
                'scope' => 'mismatch',
                'status_label' => 'Verification pending',
                'reason' => 'GSTIN and billing state conflict — provider verification has not been recorded yet.',
                'verified_at_label' => null,
            ];
        }

        return [
            'scope' => 'mismatch',
            'status_label' => match ($record->verification_status) {
                BuyerGstVerificationStatus::Verified => 'Verified registration',
                BuyerGstVerificationStatus::Pending => 'Verification pending',
                BuyerGstVerificationStatus::ReviewRequired => 'Review required',
                BuyerGstVerificationStatus::Failed => 'Verification failed',
            },
            'reason' => $this->sanitizedReason($record->review_reason),
            'verified_at_label' => $record->verified_at?->timezone(config('app.timezone'))->format('j M Y, g:i A'),
        ];
    }

    public function ensureSubmittedSnapshot(CommerceOrder $order): ?CommerceOrderBuyerGstVerification
    {
        if (! $this->requiresProviderVerification($order)) {
            return null;
        }

        $gstin = BuyerGstin::normalize($order->buyer_gstin);
        if ($gstin === null) {
            return null;
        }

        return CommerceOrderBuyerGstVerification::query()->firstOrCreate(
            ['commerce_order_id' => $order->id],
            [
                'submitted_gstin' => $gstin,
                'verification_status' => BuyerGstVerificationStatus::Pending,
                'submitted_billing_address_structured' => $order->billing_address_structured,
                'submitted_shipping_address_structured' => $order->shipping_address_structured,
            ],
        );
    }

    public function recordForOrder(CommerceOrder $order): ?CommerceOrderBuyerGstVerification
    {
        return CommerceOrderBuyerGstVerification::query()
            ->where('commerce_order_id', $order->id)
            ->first();
    }

    public function verifyAndPersist(CommerceOrder $order, bool $callProvider = true): ?CommerceOrderBuyerGstVerification
    {
        if (! $this->requiresProviderVerification($order)) {
            return null;
        }

        $gstin = BuyerGstin::normalize($order->buyer_gstin);
        if ($gstin === null) {
            return null;
        }

        return DB::transaction(function () use ($order, $gstin, $callProvider): ?CommerceOrderBuyerGstVerification {
            $record = CommerceOrderBuyerGstVerification::query()
                ->where('commerce_order_id', $order->id)
                ->lockForUpdate()
                ->first();

            if ($record === null) {
                $record = $this->ensureSubmittedSnapshot($order);
            }

            if ($record === null) {
                return null;
            }

            if ($record->verification_status === BuyerGstVerificationStatus::Verified
                && $record->submitted_gstin === $gstin) {
                return $record;
            }

            if (! $callProvider || ! $this->gateway->isConfigured()) {
                $record->fill([
                    'verification_status' => BuyerGstVerificationStatus::ReviewRequired,
                    'review_reason' => $this->gateway->isConfigured() ? 'verification_not_run' : 'verification_provider_unconfigured',
                ])->save();

                return $record->fresh();
            }

            $result = $this->gateway->verify($gstin);
            $record->fill([
                'submitted_gstin' => $gstin,
                'verification_status' => $result->status,
                'verification_source' => $this->gateway->provider(),
                'provider_correlation_id' => $result->correlationId,
                'verified_at' => $result->status === BuyerGstVerificationStatus::Verified ? now() : null,
                'legal_name' => $result->legalName,
                'registration_status' => $result->registrationStatus,
                'review_reason' => $result->reviewReason,
                'verified_registration_address_structured' => $result->verifiedAddressStructured,
                'provider_payload_hash' => $result->providerPayloadHash,
                'submitted_billing_address_structured' => $order->billing_address_structured,
                'submitted_shipping_address_structured' => $order->shipping_address_structured,
            ]);

            $billTo = $this->resolveBillTo($order, $record, $result);
            $record->bill_to_address_structured = $billTo;
            $record->invoice_classification = $this->classificationFor($order, $record, $result);

            $record->save();

            return $record->fresh();
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveBillTo(
        CommerceOrder $order,
        CommerceOrderBuyerGstVerification $record,
        BuyerGstinVerificationResult $result,
    ): ?array {
        if ($result->status !== BuyerGstVerificationStatus::Verified
            || $result->verifiedAddressStructured === null) {
            return null;
        }

        $submitted = StatutoryBillingStructured::fromStored($order->billing_address_structured);
        $verified = $result->verifiedAddressStructured;
        $gstinState = GstStateCodes::nameForCode(BuyerGstin::stateCode($result->gstin ?? '') ?? '');
        $verifiedState = StatutoryBillingStructured::nullable($verified['state'] ?? null);
        $submittedState = $submitted !== null
            ? StatutoryBillingStructured::nullable($submitted['state'] ?? null)
            : null;

        $mismatch = $submittedState !== null
            && $gstinState !== null
            && $submittedState !== $gstinState
            && $verifiedState === $gstinState;

        if ($mismatch || ($submittedState !== null && $submittedState !== $verifiedState)) {
            return $verified;
        }

        if ($submitted !== null && $this->structuredMatchesGstinState($submitted, $result->gstin)) {
            return $submitted;
        }

        return $verified;
    }

    /**
     * @param  array<string, mixed>  $structured
     */
    private function structuredMatchesGstinState(array $structured, ?string $gstin): bool
    {
        $code = BuyerGstin::stateCode($gstin);
        $state = StatutoryBillingStructured::nullable($structured['state'] ?? null);
        if ($code === null || $state === null) {
            return false;
        }

        return GstStateCodes::codeForName($state) === $code;
    }

    private function classificationFor(
        CommerceOrder $order,
        CommerceOrderBuyerGstVerification $record,
        BuyerGstinVerificationResult $result,
    ): ?CommerceInvoiceGstClassification {
        if (BuyerGstin::normalize($order->buyer_gstin) === null) {
            return CommerceInvoiceGstClassification::B2c;
        }

        if ($result->status === BuyerGstVerificationStatus::Verified && $record->bill_to_address_structured !== null) {
            return CommerceInvoiceGstClassification::B2b;
        }

        return null;
    }

    private function sanitizedReason(?string $reason): ?string
    {
        return match ($reason) {
            'inactive_registration' => 'GST registration is not active.',
            'registration_address_incomplete' => 'Provider registration address is incomplete.',
            'provider_unavailable' => 'Provider temporarily unavailable — retry pending.',
            'provider_rate_limited' => 'Provider rate limited — retry pending.',
            'verification_provider_unconfigured' => 'Verification provider is not configured.',
            'verification_not_run' => 'Verification has not completed.',
            'invalid_gstin_format' => 'GSTIN format is invalid.',
            'gstin_state_mismatch' => 'GSTIN and billing state conflict.',
            default => $reason !== null && $reason !== '' ? 'GST review required.' : null,
        };
    }
}
