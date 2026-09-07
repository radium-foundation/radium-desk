<?php

namespace App\Services\HardwareFulfilment;

use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentPaymentEvidence;
use App\Models\Order;
use App\Services\HardwareFulfilment\Data\HardwarePaymentEvidenceDraft;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HardwareFulfilmentPaymentCorrelationService
{
    public const VERIFIED_STATUS = 'SUCCESS';

    public function recordPaidEvidence(HardwarePaymentEvidenceDraft $draft): ?HardwareFulfilmentPaymentEvidence
    {
        if (HardwareFulfilmentEligibility::isFrozenSourceId($draft->sourceId)) {
            return null;
        }

        if (! HardwareFulfilmentEligibility::looksLikeHardwareSourceId($draft->sourceId)) {
            return null;
        }

        $paymentId = $this->normalizeId($draft->cashfreePaymentId);
        if ($paymentId === null) {
            throw ValidationException::withMessages([
                'cashfree_payment_id' => 'Hardware payment evidence requires a Cashfree payment id. Values are not invented.',
            ]);
        }

        $status = strtoupper(trim($draft->paymentStatus));
        $verified = $status === self::VERIFIED_STATUS;

        return DB::transaction(function () use ($draft, $paymentId, $status, $verified): HardwareFulfilmentPaymentEvidence {
            $existing = HardwareFulfilmentPaymentEvidence::query()
                ->where('cashfree_payment_id', $paymentId)
                ->lockForUpdate()
                ->first();

            $fulfilment = $this->findFulfilment($draft->sourceId);

            if ($existing !== null) {
                return $this->refreshExisting($existing, $draft, $fulfilment, $verified, $status);
            }

            try {
                $evidence = HardwareFulfilmentPaymentEvidence::query()->create([
                    'source_id' => strtoupper($draft->sourceId),
                    'hardware_fulfilment_id' => $fulfilment?->id,
                    'commerce_order_id' => $fulfilment?->commerce_order_id,
                    'support_order_id' => $draft->supportOrderId ?? $fulfilment?->support_order_id,
                    'cashfree_payment_id' => $paymentId,
                    'merchant_order_id' => $draft->merchantOrderId ?? $draft->sourceId,
                    'cf_order_id' => $this->normalizeId($draft->cfOrderId),
                    'gateway_order_id' => $this->normalizeId($draft->gatewayOrderId),
                    'gateway_payment_id' => $this->normalizeId($draft->gatewayPaymentId),
                    'bank_reference' => $this->normalizeId($draft->bankReference),
                    'payment_status' => $status,
                    'verified' => $verified,
                    'payment_amount' => $draft->paymentAmount,
                    'payment_method' => $this->normalizeId($draft->paymentMethod),
                    'paid_at' => $draft->paidAt,
                    'cashfree_webhook_log_id' => $draft->cashfreeWebhookLogId,
                    'last_error' => $verified ? null : 'Payment status is not a verified SUCCESS.',
                    'metadata' => $draft->metadata,
                ]);
            } catch (UniqueConstraintViolationException) {
                $again = HardwareFulfilmentPaymentEvidence::query()
                    ->where('cashfree_payment_id', $paymentId)
                    ->firstOrFail();

                return $this->refreshExisting($again, $draft, $fulfilment, $verified, $status);
            }

            if ($verified && $fulfilment !== null) {
                $this->applyVerifiedCorrelation($fulfilment, $evidence);
            }

            return $evidence->fresh() ?? $evidence;
        });
    }

    public function recordFromDeskOrder(Order $order): ?HardwareFulfilmentPaymentEvidence
    {
        $sourceId = trim((string) $order->order_id);
        if ($sourceId === '' || ! HardwareFulfilmentEligibility::looksLikeHardwareSourceId($sourceId)) {
            return null;
        }

        if (HardwareFulfilmentEligibility::isFrozenSourceId($sourceId)) {
            return null;
        }

        $paymentId = $this->normalizeId($order->cashfree_payment_id);
        if ($paymentId === null) {
            return null;
        }

        return $this->recordPaidEvidence(new HardwarePaymentEvidenceDraft(
            sourceId: $sourceId,
            cashfreePaymentId: $paymentId,
            paymentStatus: self::VERIFIED_STATUS,
            supportOrderId: (int) $order->id,
            merchantOrderId: $sourceId,
            gatewayOrderId: $this->normalizeId($order->gateway_order_id),
            gatewayPaymentId: $this->normalizeId($order->gateway_payment_id),
            bankReference: $this->normalizeId($order->bank_reference),
            paymentAmount: is_numeric($order->payment_amount) ? (float) $order->payment_amount : null,
            paymentMethod: $this->normalizeId($order->payment_method),
            paidAt: $order->payment_date !== null ? (string) $order->payment_date : null,
            metadata: [
                'source' => 'desk_order',
                'desk_order_id' => $order->id,
            ],
        ));
    }

    public function attachPendingEvidence(HardwareFulfilment $fulfilment): void
    {
        if (HardwareFulfilmentEligibility::isFrozenSourceId((string) $fulfilment->source_id)) {
            return;
        }

        $rows = HardwareFulfilmentPaymentEvidence::query()
            ->where('source_id', strtoupper((string) $fulfilment->source_id))
            ->where(function ($query) use ($fulfilment): void {
                $query->whereNull('hardware_fulfilment_id')
                    ->orWhere('hardware_fulfilment_id', $fulfilment->id);
            })
            ->get();

        foreach ($rows as $row) {
            $updates = [];
            if ($row->hardware_fulfilment_id === null) {
                $updates['hardware_fulfilment_id'] = $fulfilment->id;
            }
            if ($row->commerce_order_id === null) {
                $updates['commerce_order_id'] = $fulfilment->commerce_order_id;
            }
            if ($row->support_order_id === null && $fulfilment->support_order_id !== null) {
                $updates['support_order_id'] = $fulfilment->support_order_id;
            }
            if ($updates !== []) {
                $row->forceFill($updates)->save();
            }

            if ($row->verified) {
                $this->applyVerifiedCorrelation($fulfilment, $row);
            }
        }
    }

    private function refreshExisting(
        HardwareFulfilmentPaymentEvidence $existing,
        HardwarePaymentEvidenceDraft $draft,
        ?HardwareFulfilment $fulfilment,
        bool $verified,
        string $status,
    ): HardwareFulfilmentPaymentEvidence {
        $updates = [];

        if ($existing->hardware_fulfilment_id === null && $fulfilment !== null) {
            $updates['hardware_fulfilment_id'] = $fulfilment->id;
            $updates['commerce_order_id'] = $fulfilment->commerce_order_id;
        }
        if ($existing->support_order_id === null && $draft->supportOrderId !== null) {
            $updates['support_order_id'] = $draft->supportOrderId;
        }

        if (! $existing->verified && $verified) {
            $updates['verified'] = true;
            $updates['payment_status'] = $status;
            $updates['last_error'] = null;
        }

        if ($updates !== []) {
            $existing->forceFill($updates)->save();
        }

        if ($existing->verified && $fulfilment !== null) {
            $this->applyVerifiedCorrelation($fulfilment, $existing);
        }

        return $existing->fresh() ?? $existing;
    }

    private function applyVerifiedCorrelation(
        HardwareFulfilment $fulfilment,
        HardwareFulfilmentPaymentEvidence $evidence,
    ): void {
        $locked = HardwareFulfilment::query()
            ->whereKey($fulfilment->id)
            ->lockForUpdate()
            ->firstOrFail();

        $updates = [];
        if ($locked->cashfree_payment_id === null) {
            $updates['cashfree_payment_id'] = $evidence->cashfree_payment_id;
        }
        if ($locked->support_order_id === null && $evidence->support_order_id !== null) {
            $updates['support_order_id'] = $evidence->support_order_id;
        }
        if ($locked->paid_recognized_at === null) {
            $updates['paid_recognized_at'] = now();
        }

        if ($updates !== []) {
            $locked->forceFill($updates)->save();
        }
    }

    private function findFulfilment(string $sourceId): ?HardwareFulfilment
    {
        return HardwareFulfilment::query()
            ->whereIn('source_id', [$sourceId, strtoupper($sourceId)])
            ->first();
    }

    private function normalizeId(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
