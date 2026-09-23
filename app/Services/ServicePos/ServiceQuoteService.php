<?php

namespace App\Services\ServicePos;

use App\Enums\ServiceOrderPaymentStatus;
use App\Enums\ServiceOrderStatus;
use App\Enums\ServiceQuoteStatus;
use App\Models\InventoryBranch;
use App\Models\InventoryCustomer;
use App\Models\ServiceItem;
use App\Models\ServiceOrder;
use App\Models\ServiceQuote;
use App\Models\ServiceQuoteLine;
use App\Models\User;
use App\Services\ServiceOrderReferenceService;
use App\Services\StatutoryInvoice\BuyerGstin;
use App\Services\StatutoryInvoice\RdServiceStatutoryDisplayName;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServiceQuoteService
{
    public function __construct(
        private readonly ServiceOrderReferenceService $serviceOrderReferences,
    ) {}

    /**
     * @param  list<array{
     *     service_item_id?: int|null,
     *     description?: string|null,
     *     qty?: int,
     *     unit_price_ex_gst?: float|string|null,
     *     discount?: float|string|null,
     *     sac_code?: string|null,
     *     gst_rate?: float|string|null
     * }>  $lines
     */
    public function createQuote(
        InventoryCustomer $customer,
        InventoryBranch $branch,
        array $lines,
        User $actor,
        ?string $billingAddress = null,
        ?string $billingState = null,
        ?string $placeOfSupplyState = null,
        ?string $buyerGstin = null,
        float $headerDiscount = 0,
        ?string $idempotencyKey = null,
        ?array $billingAddressStructured = null,
        ?string $paymentReference = null,
    ): ServiceQuote {
        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'Add at least one service line.',
            ]);
        }

        $idempotencyKey = $idempotencyKey !== null && trim($idempotencyKey) !== ''
            ? trim($idempotencyKey)
            : null;

        if ($idempotencyKey !== null) {
            $existing = ServiceQuote::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return $existing->load('lines');
            }
        }

        return DB::transaction(function () use (
            $customer,
            $branch,
            $lines,
            $actor,
            $billingAddress,
            $billingState,
            $placeOfSupplyState,
            $buyerGstin,
            $headerDiscount,
            $idempotencyKey,
            $billingAddressStructured,
            $paymentReference,
        ): ServiceQuote {
            $quote = ServiceQuote::query()->create([
                'quote_number' => 'SQ-TMP-'.strtoupper(bin2hex(random_bytes(4))),
                'status' => ServiceQuoteStatus::Draft,
                'customer_id' => $customer->id,
                'branch_id' => $branch->id,
                'buyer_name' => $customer->name,
                'buyer_phone' => $customer->phone,
                'buyer_email' => $customer->email,
                'buyer_gstin' => BuyerGstin::normalize($buyerGstin ?? $customer->gstin),
                'billing_address' => $billingAddress,
                'billing_address_structured' => $billingAddressStructured,
                'billing_state' => $billingState,
                'place_of_supply_state' => $placeOfSupplyState ?? $billingState,
                'payment_reference' => $paymentReference,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $actor->id,
            ]);

            $subtotal = 0.0;
            $taxTotal = 0.0;
            $lineDiscountTotal = 0.0;

            foreach ($lines as $index => $lineInput) {
                $resolved = $this->resolveLine($lineInput, $index);
                $subtotal += $resolved['line_subtotal'];
                $taxTotal += $resolved['tax_total'];
                $lineDiscountTotal += $resolved['discount'];

                ServiceQuoteLine::query()->create([
                    'quote_id' => $quote->id,
                    'line_no' => $index + 1,
                    ...$resolved['payload'],
                ]);
            }

            $discount = round($headerDiscount + $lineDiscountTotal, 2);
            $total = round($subtotal - $discount + $taxTotal, 2);

            $quote->update([
                'quote_number' => sprintf('SQ-%s-%06d', now()->format('Y'), $quote->id),
                'subtotal' => $subtotal,
                'tax_total' => $taxTotal,
                'discount' => $discount,
                'total' => $total,
            ]);

            return $quote->fresh(['lines']) ?? $quote;
        });
    }

    public function convertToOrder(ServiceQuote $quote, User $actor, ?string $idempotencyKey = null): ServiceOrder
    {
        $idempotencyKey = $idempotencyKey !== null && trim($idempotencyKey) !== ''
            ? trim($idempotencyKey)
            : 'service-order-from-quote:'.$quote->id;

        $existingByKey = ServiceOrder::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existingByKey !== null) {
            return $existingByKey->load('lines');
        }

        if ($quote->converted_service_order_id !== null) {
            $existing = ServiceOrder::query()->find($quote->converted_service_order_id);
            if ($existing !== null) {
                return $existing->load('lines');
            }
        }

        return DB::transaction(function () use ($quote, $actor, $idempotencyKey): ServiceOrder {
            $quote = ServiceQuote::query()->lockForUpdate()->findOrFail($quote->id);

            if ($quote->status === ServiceQuoteStatus::Converted && $quote->converted_service_order_id !== null) {
                return ServiceOrder::query()->findOrFail($quote->converted_service_order_id)->load('lines');
            }

            if (in_array($quote->status, [ServiceQuoteStatus::Cancelled, ServiceQuoteStatus::Expired], true)) {
                throw ValidationException::withMessages([
                    'quote' => 'Cancelled or expired quotes cannot be converted.',
                ]);
            }

            if ($quote->lines()->count() === 0) {
                throw ValidationException::withMessages([
                    'quote' => 'Quote has no lines.',
                ]);
            }

            $order = ServiceOrder::query()->create([
                'order_number' => 'SVC-TMP-'.strtoupper(bin2hex(random_bytes(4))),
                'quote_id' => $quote->id,
                'customer_id' => $quote->customer_id,
                'branch_id' => $quote->branch_id,
                'buyer_name' => $quote->buyer_name,
                'buyer_phone' => $quote->buyer_phone,
                'buyer_email' => $quote->buyer_email,
                'buyer_gstin' => $quote->buyer_gstin,
                'billing_address' => $quote->billing_address,
                'billing_address_structured' => $quote->billing_address_structured,
                'billing_state' => $quote->billing_state,
                'place_of_supply_state' => $quote->place_of_supply_state,
                'payment_reference' => $quote->payment_reference,
                'status' => ServiceOrderStatus::Open,
                'payment_status' => ServiceOrderPaymentStatus::Unpaid,
                'subtotal' => $quote->subtotal,
                'tax_total' => $quote->tax_total,
                'discount' => $quote->discount,
                'total' => $quote->total,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $actor->id,
            ]);

            foreach ($quote->lines as $line) {
                $order->lines()->create([
                    'line_no' => $line->line_no,
                    'service_item_id' => $line->service_item_id,
                    'description' => $line->description,
                    'sac_code' => $line->sac_code,
                    'gst_rate' => $line->gst_rate,
                    'qty' => $line->qty,
                    'unit_price_ex_gst' => $line->unit_price_ex_gst,
                    'discount' => $line->discount,
                    'taxable_value' => $line->taxable_value,
                    'tax_total' => $line->tax_total,
                    'line_total' => $line->line_total,
                ]);
            }

            $order->update([
                'order_number' => $this->serviceOrderReferences->allocate(),
            ]);

            $quote->update([
                'status' => ServiceQuoteStatus::Converted,
                'converted_service_order_id' => $order->id,
            ]);

            return $order->fresh(['lines']) ?? $order;
        });
    }

    /**
     * @param  array<string, mixed>  $lineInput
     * @return array{
     *     line_subtotal: float,
     *     tax_total: float,
     *     discount: float,
     *     payload: array<string, mixed>
     * }
     */
    private function resolveLine(array $lineInput, int $index): array
    {
        $item = null;
        if (! empty($lineInput['service_item_id'])) {
            $item = ServiceItem::query()->find($lineInput['service_item_id']);
            if ($item === null || ! $item->is_active) {
                throw ValidationException::withMessages([
                    "lines.{$index}.service_item_id" => 'Service item is missing or inactive.',
                ]);
            }
        }

        $qty = max(1, (int) ($lineInput['qty'] ?? 1));
        $unitPrice = $lineInput['unit_price_ex_gst'] ?? $item?->price_ex_gst ?? 0;
        $unitPrice = round((float) $unitPrice, 2);
        $discount = round((float) ($lineInput['discount'] ?? 0), 2);
        $gstRate = round((float) ($lineInput['gst_rate'] ?? $item?->gst_rate ?? 0), 2);
        $sacCode = $lineInput['sac_code'] ?? $item?->sac_code;
        $description = trim((string) ($lineInput['description'] ?? RdServiceStatutoryDisplayName::catalogName($item) ?? ''));
        if ($description === '') {
            throw ValidationException::withMessages([
                "lines.{$index}.description" => 'Line description is required.',
            ]);
        }

        $lineSubtotal = round($unitPrice * $qty, 2);
        $taxable = round($lineSubtotal - $discount, 2);
        if ($taxable < 0) {
            throw ValidationException::withMessages([
                "lines.{$index}.discount" => 'Discount cannot exceed line subtotal.',
            ]);
        }

        $taxTotal = round($taxable * ($gstRate / 100), 2);
        $lineTotal = round($taxable + $taxTotal, 2);

        return [
            'line_subtotal' => $lineSubtotal,
            'tax_total' => $taxTotal,
            'discount' => $discount,
            'payload' => [
                'service_item_id' => $item?->id,
                'description' => $description,
                'sac_code' => $sacCode,
                'gst_rate' => $gstRate,
                'qty' => $qty,
                'unit_price_ex_gst' => $unitPrice,
                'discount' => $discount,
                'taxable_value' => $taxable,
                'tax_total' => $taxTotal,
                'line_total' => $lineTotal,
            ],
        ];
    }
}
