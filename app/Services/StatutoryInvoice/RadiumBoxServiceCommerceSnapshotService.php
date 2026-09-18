<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\CommerceOrderStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\Order;
use App\Services\OrderLookup\OrderEnrichmentLookupService;
use App\Services\StatutoryInvoice\Data\RadiumBoxServiceCommerceLookup;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceMintRequest;
use App\Support\BusinessOrderId;
use App\Support\Finance\IndianStates;
use App\Support\StatutoryInvoice\StatutoryBillingStructured;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RadiumBoxServiceCommerceSnapshotService
{
    public const ATTEMPTS = 5;

    public function __construct(
        private readonly OrderEnrichmentLookupService $lookup,
        private readonly RadiumBoxServiceCommerceLookupMapper $mapper,
        private readonly RadiumBoxServiceCommerceLineBuilder $lineBuilder,
    ) {}

    public function ensureForSupportOrder(Order $order): CommerceOrder
    {
        $sourceId = trim((string) $order->order_id);
        if (! BusinessOrderId::isRadiumBoxService($sourceId)) {
            throw ValidationException::withMessages([
                'support_order' => 'Only RadiumBox RB* service orders can use the radiumbox.com service commerce snapshot.',
            ]);
        }

        $existing = $this->findExisting($sourceId);
        if ($existing !== null) {
            $this->assertSupportOrderLink($existing, $order);
            $this->linkSupportOrderIfMissing($existing, $order);

            return $existing->load('items');
        }

        $lookup = $this->lookup->fetchRadiumBoxServiceCommerce($sourceId);
        $this->assertLookupComplete($lookup);

        return DB::transaction(function () use ($order, $sourceId, $lookup): CommerceOrder {
            $again = CommerceOrder::query()
                ->where('channel', StatutoryInvoiceChannel::RadiumBoxCom)
                ->where('source_type', StatutoryInvoiceSourceType::CommerceOrder->value)
                ->where('source_id', $sourceId)
                ->lockForUpdate()
                ->first();

            if ($again !== null) {
                $this->assertSupportOrderLink($again, $order);
                $this->linkSupportOrderIfMissing($again, $order);

                return $again->load('items');
            }

            $hash = $this->payloadHash($lookup, $order);
            $commercialAt = $this->commercialAt($lookup, $order);

            $commerce = CommerceOrder::query()->create([
                'order_no' => 'CO-TMP-'.bin2hex(random_bytes(8)),
                'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
                'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
                'source_id' => $sourceId,
                'source_order_id' => $sourceId,
                'idempotency_key' => StatutoryInvoiceMintRequest::sourceKey(
                    StatutoryInvoiceChannel::RadiumBoxCom,
                    StatutoryInvoiceSourceType::CommerceOrder,
                    $sourceId,
                ),
                'payload_hash' => $hash,
                'status' => CommerceOrderStatus::InvoicePending,
                'invoice_eligible' => true,
                'payment_status' => $this->paymentStatus($order),
                'payment_provider' => filled($order->cashfree_payment_id) ? 'cashfree' : null,
                'payment_reference' => $this->paymentReference($order),
                'payment_method' => $order->payment_method,
                'currency' => 'INR',
                'customer_name' => $lookup->customerName ?? $order->customer_name,
                'customer_phone' => $lookup->customerPhone ?? $order->customer_phone,
                'customer_email' => $lookup->customerEmail ?? $order->customer_email,
                'buyer_gstin' => $this->buyerGstinForSnapshot($lookup, $order),
                'billing_address' => $lookup->billingAddress,
                'billing_state' => $lookup->billingState,
                'billing_address_structured' => $lookup->billingAddressStructured,
                'place_of_supply_state' => $lookup->placeOfSupplyState,
                'taxable_value' => $lookup->taxableValue,
                'tax_total' => $lookup->taxTotal,
                'order_value' => $lookup->lineTotal,
                'ordered_at' => $commercialAt,
                'paid_at' => $commercialAt,
                'received_at' => now(),
                'support_order_id' => $order->id,
            ]);

            $commerce->update([
                'order_no' => sprintf('CO-%06d', $commerce->id),
            ]);

            foreach ($this->lineBuilder->build($lookup) as $line) {
                CommerceOrderItem::query()->create([
                    'commerce_order_id' => $commerce->id,
                    'line_no' => $line['line_no'],
                    'description' => $line['description'],
                    'variant' => $line['variant'],
                    'hsn_sac' => $line['hsn_sac'],
                    'qty' => $line['qty'],
                    'unit_price' => $line['unit_price'],
                    'gst_percentage' => $line['gst_percentage'],
                    'taxable_value' => $line['taxable_value'],
                    'tax_total' => $line['tax_total'],
                    'line_total' => $line['line_total'],
                ]);
            }

            return $commerce->fresh(['items']) ?? $commerce;
        }, self::ATTEMPTS);
    }

    private function findExisting(string $sourceId): ?CommerceOrder
    {
        return CommerceOrder::query()
            ->where('channel', StatutoryInvoiceChannel::RadiumBoxCom)
            ->where('source_type', StatutoryInvoiceSourceType::CommerceOrder->value)
            ->where('source_id', $sourceId)
            ->first();
    }

    private function assertSupportOrderLink(CommerceOrder $commerce, Order $order): void
    {
        if ($commerce->support_order_id === null) {
            return;
        }

        if ((int) $commerce->support_order_id !== (int) $order->id) {
            throw ValidationException::withMessages([
                'support_order' => 'The radiumbox.com commerce order is already linked to a different Desk order.',
            ]);
        }
    }

    private function linkSupportOrderIfMissing(CommerceOrder $commerce, Order $order): void
    {
        if ($commerce->support_order_id === null) {
            $commerce->forceFill(['support_order_id' => $order->id])->save();
        }
    }

    private function assertLookupComplete(RadiumBoxServiceCommerceLookup $lookup): void
    {
        $errors = [];

        if ($lookup->billingState === null || ! IndianStates::contains($lookup->billingState)) {
            $errors[] = 'billing_state is missing or not a recognised Indian state.';
        }

        if ($lookup->placeOfSupplyState === null || ! IndianStates::contains($lookup->placeOfSupplyState)) {
            $errors[] = 'place_of_supply_state is missing or not a recognised Indian state.';
        }

        if ($lookup->billingAddress === null && $lookup->billingAddressStructured === null) {
            $errors[] = 'billing address is missing.';
        }

        foreach ([
            'taxable_value' => $lookup->taxableValue,
            'tax_total' => $lookup->taxTotal,
            'line_total' => $lookup->lineTotal,
            'gst_percentage' => $lookup->gstPercentage,
        ] as $label => $value) {
            if ($value === null) {
                $errors[] = $label.' is missing.';
            }
        }

        if ($lookup->serviceDescription === null || trim($lookup->serviceDescription) === '') {
            $errors[] = 'service description is missing.';
        }

        if ($lookup->catalogHsnSac === null || trim($lookup->catalogHsnSac) === '') {
            $errors[] = 'catalog HSN/SAC is missing.';
        }

        if ($lookup->taxableValue !== null
            && $lookup->taxTotal !== null
            && $lookup->lineTotal !== null
            && round($lookup->taxableValue + $lookup->taxTotal, 2) !== round($lookup->lineTotal, 2)) {
            $errors[] = 'taxable_value + tax_total must equal line_total.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages([
                'service_commerce' => $errors,
            ]);
        }
    }

    private function buyerGstinForSnapshot(RadiumBoxServiceCommerceLookup $lookup, Order $order): ?string
    {
        if ($lookup->buyerGstin !== null) {
            return $lookup->buyerGstin;
        }

        $deskGstin = BuyerGstin::normalize($order->gst_number);

        return BuyerGstin::isValid($deskGstin) ? $deskGstin : null;
    }

    private function paymentStatus(Order $order): string
    {
        if (filled($order->transaction_id) || filled($order->cashfree_payment_id)) {
            return 'paid';
        }

        return 'unpaid';
    }

    private function paymentReference(Order $order): ?string
    {
        foreach ([$order->cashfree_payment_id, $order->gateway_payment_id, $order->gateway_order_id] as $value) {
            $normalized = is_string($value) ? trim($value) : '';
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    private function commercialAt(RadiumBoxServiceCommerceLookup $lookup, Order $order): Carbon
    {
        foreach ([$order->payment_date, $order->completed_at, $lookup->orderedAt] as $candidate) {
            if ($candidate instanceof Carbon) {
                return $candidate;
            }

            if (is_string($candidate) && trim($candidate) !== '') {
                try {
                    return Carbon::parse($candidate, config('app.timezone', 'Asia/Kolkata'));
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return now();
    }

    private function payloadHash(RadiumBoxServiceCommerceLookup $lookup, Order $order): string
    {
        $structured = StatutoryBillingStructured::fromStored($lookup->billingAddressStructured);

        return hash('sha256', json_encode([
            'source_id' => $lookup->rdOrderId,
            'billing_state' => $lookup->billingState,
            'place_of_supply_state' => $lookup->placeOfSupplyState,
            'billing_address' => $lookup->billingAddress,
            'billing_address_structured' => $structured,
            'buyer_gstin' => $this->buyerGstinForSnapshot($lookup, $order),
            'taxable_value' => $lookup->taxableValue,
            'tax_total' => $lookup->taxTotal,
            'line_total' => $lookup->lineTotal,
            'gst_percentage' => $lookup->gstPercentage,
            'service_description' => $lookup->serviceDescription,
            'catalog_hsn_sac' => $lookup->catalogHsnSac,
            'duration_type' => $lookup->durationType,
            'duration_price' => $lookup->durationPrice,
            'base_taxable_value' => $lookup->baseTaxableValue,
        ], JSON_THROW_ON_ERROR));
    }
}
