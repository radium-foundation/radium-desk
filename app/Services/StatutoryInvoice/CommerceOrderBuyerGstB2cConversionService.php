<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\BuyerGstB2cConversionStatus;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderBuyerGstB2cConversion;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Support\StatutoryInvoice\BuyerGstB2cConversionPolicy;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CommerceOrderBuyerGstB2cConversionService
{
    public function __construct(
        private readonly CommerceOrderBuyerGstVerificationService $verifications,
    ) {}

    public function approvedConversion(CommerceOrder $order): ?CommerceOrderBuyerGstB2cConversion
    {
        return CommerceOrderBuyerGstB2cConversion::query()
            ->where('commerce_order_id', $order->id)
            ->where('status', BuyerGstB2cConversionStatus::Approved)
            ->first();
    }

    public function attachIssuedStatutoryInvoice(CommerceOrder $order, StatutoryInvoice $invoice): void
    {
        $buyerGstin = is_string($invoice->buyer_gstin) ? trim($invoice->buyer_gstin) : '';
        if ($buyerGstin !== '') {
            return;
        }

        $conversion = $this->approvedConversion($order);
        if ($conversion === null || $conversion->statutory_invoice_id !== null) {
            return;
        }

        $conversion->forceFill(['statutory_invoice_id' => $invoice->id])->save();
    }

    public function approve(CommerceOrder $order, User $actor, string $decisionReason): CommerceOrderBuyerGstB2cConversion
    {
        if (! BuyerGstB2cConversionPolicy::fallbackPolicyEnabled()) {
            throw ValidationException::withMessages([
                'policy' => 'B2C fallback policy is disabled.',
            ]);
        }

        if ($this->orderHasIssuedStatutoryInvoice($order)) {
            throw ValidationException::withMessages([
                'commerce_order' => 'Cannot approve B2C conversion after statutory invoice issuance.',
            ]);
        }

        $record = $this->verifications->recordForOrder($order);
        if (! BuyerGstB2cConversionPolicy::isEligibleForManualConversion($record)) {
            throw ValidationException::withMessages([
                'commerce_order' => 'This order is not eligible for authorized B2C conversion.',
            ]);
        }

        $masked = BuyerGstB2cConversionPolicy::maskedGstinForRemark($record->submitted_gstin);
        $remark = BuyerGstB2cConversionPolicy::defaultInvoiceRemark(
            $masked,
            $record->registration_status,
            (string) $record->review_reason,
        );

        return DB::transaction(function () use ($order, $actor, $decisionReason, $record, $remark): CommerceOrderBuyerGstB2cConversion {
            CommerceOrder::query()->whereKey($order->id)->lockForUpdate()->first();

            $existing = CommerceOrderBuyerGstB2cConversion::query()
                ->where('commerce_order_id', $order->id)
                ->lockForUpdate()
                ->first();

            if ($existing?->status === BuyerGstB2cConversionStatus::Approved) {
                return $existing;
            }

            if ($existing?->status === BuyerGstB2cConversionStatus::Rejected) {
                throw ValidationException::withMessages([
                    'commerce_order' => 'A rejected B2C conversion exists for this order.',
                ]);
            }

            $payload = [
                'commerce_order_id' => $order->id,
                'commerce_order_buyer_gst_verification_id' => $record->id,
                'submitted_gstin' => $record->submitted_gstin,
                'verification_status' => $record->verification_status->value,
                'registration_status' => $record->registration_status,
                'review_reason' => $record->review_reason,
                'provider_payload_hash' => $record->provider_payload_hash,
                'status' => BuyerGstB2cConversionStatus::Approved,
                'authorized_by' => $actor->id,
                'authorized_at' => now(),
                'decision_reason' => trim($decisionReason) !== '' ? trim($decisionReason) : null,
                'invoice_remark' => $remark,
            ];

            if ($existing !== null) {
                $existing->fill($payload)->save();

                return $existing->fresh() ?? $existing;
            }

            try {
                return CommerceOrderBuyerGstB2cConversion::query()->create($payload);
            } catch (UniqueConstraintViolationException $exception) {
                $wonRace = CommerceOrderBuyerGstB2cConversion::query()
                    ->where('commerce_order_id', $order->id)
                    ->where('status', BuyerGstB2cConversionStatus::Approved)
                    ->first();
                if ($wonRace !== null) {
                    return $wonRace;
                }

                throw $exception;
            }
        }, 5);
    }

    public function reject(CommerceOrder $order, User $actor, string $decisionReason): CommerceOrderBuyerGstB2cConversion
    {
        if (! BuyerGstB2cConversionPolicy::fallbackPolicyEnabled()) {
            throw ValidationException::withMessages([
                'policy' => 'B2C fallback policy is disabled.',
            ]);
        }

        $record = $this->verifications->recordForOrder($order);
        if ($record === null) {
            throw new ModelNotFoundException('No verification record for order.');
        }

        return DB::transaction(function () use ($order, $actor, $decisionReason, $record): CommerceOrderBuyerGstB2cConversion {
            $existing = CommerceOrderBuyerGstB2cConversion::query()
                ->where('commerce_order_id', $order->id)
                ->lockForUpdate()
                ->first();

            if ($existing?->status === BuyerGstB2cConversionStatus::Approved) {
                throw ValidationException::withMessages([
                    'commerce_order' => 'Cannot reject after an approved B2C conversion.',
                ]);
            }

            $payload = [
                'commerce_order_id' => $order->id,
                'commerce_order_buyer_gst_verification_id' => $record->id,
                'submitted_gstin' => $record->submitted_gstin,
                'verification_status' => $record->verification_status->value,
                'registration_status' => $record->registration_status,
                'review_reason' => $record->review_reason,
                'provider_payload_hash' => $record->provider_payload_hash,
                'status' => BuyerGstB2cConversionStatus::Rejected,
                'authorized_by' => $actor->id,
                'authorized_at' => now(),
                'decision_reason' => trim($decisionReason) !== '' ? trim($decisionReason) : null,
                'invoice_remark' => '',
            ];

            if ($existing !== null) {
                $existing->fill($payload)->save();

                return $existing->fresh() ?? $existing;
            }

            return CommerceOrderBuyerGstB2cConversion::query()->create($payload);
        });
    }

    private function orderHasIssuedStatutoryInvoice(CommerceOrder $order): bool
    {
        return StatutoryInvoice::query()
            ->where('channel', $order->channel)
            ->where('source_type', StatutoryInvoiceSourceType::CommerceOrder)
            ->where('source_id', $order->source_id)
            ->exists();
    }
}
