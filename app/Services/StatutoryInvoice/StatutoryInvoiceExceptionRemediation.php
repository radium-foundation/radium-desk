<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\InventoryProduct;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use App\Support\StatutoryInvoice\StatutoryBillingStructured;
use Illuminate\Support\Facades\DB;

/**
 * Controlled statutory snapshot corrections for verified Gate 1 exceptions.
 * Uses direct SQL updates to avoid mutating immutable financial identity fields.
 */
final class StatutoryInvoiceExceptionRemediation
{
    /**
     * Verified B2B IRN exceptions from Gate 1 (P-07-09-219).
     *
     * @var list<int>
     */
    public const EXCEPTION_INVOICE_IDS = [
        79, 80, 84, 144, 157, 178, 356, 385, 417, 420, 444, 460, 472, 525, 550, 583, 604, 609, 616, 618,
        621, 623, 624, 629, 631, 636, 637, 638, 684, 693, 717, 738, 793, 805, 808, 835, 901, 957, 1027, 1028,
        1050, 1052, 1089, 1091, 1131,
    ];

    public function __construct(
        private readonly ServiceStatutoryClassification $serviceStatutory,
        private readonly PlaceOfSupplyResolver $placeOfSupply,
        private readonly EInvoiceInputReadiness $readiness,
        private readonly ServiceSacResolver $serviceSac,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function remediate(StatutoryInvoice $invoice): array
    {
        if (! in_array((int) $invoice->id, self::EXCEPTION_INVOICE_IDS, true)) {
            return [
                'invoice_id' => $invoice->id,
                'applied' => false,
                'reason' => 'invoice_not_in_exception_set',
            ];
        }

        $invoice->loadMissing('items');
        $changes = [];
        $structured = $this->resolveStructuredBilling($invoice);
        if ($structured !== null) {
            $changes['billing_address_structured'] = json_encode($structured, JSON_UNESCAPED_UNICODE);
        }

        $pos = $this->placeOfSupply->resolveForInvoice($invoice->fresh(['items']));
        if ($pos->isResolvable()) {
            $changes['place_of_supply_state'] = $pos->state;
            $changes['place_of_supply_state_code'] = $pos->stateCode;
            $changes['place_of_supply_source'] = $pos->source;
        }

        if ($changes !== []) {
            DB::table('statutory_invoices')->where('id', $invoice->id)->update($changes);
        }

        foreach ($invoice->items as $item) {
            $lineChanges = $this->lineRemediation($invoice, $item);
            if ($lineChanges !== []) {
                DB::table('statutory_invoice_items')
                    ->where('id', $item->id)
                    ->update($lineChanges);
            }
        }

        $fresh = StatutoryInvoice::query()->with('items')->findOrFail($invoice->id);
        $readiness = $this->readiness->evaluate($fresh);

        return [
            'invoice_id' => $invoice->id,
            'invoice_number' => (string) $invoice->invoice_number,
            'applied' => true,
            'invoice_changes' => array_keys($changes),
            'ready' => $readiness->ready,
            'blocked_reasons' => $readiness->blockedReasonLines(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveStructuredBilling(StatutoryInvoice $invoice): ?array
    {
        $existing = StatutoryBillingStructured::fromStored(
            $invoice->billing_address_structured,
        );
        if ($existing !== null && StatutoryBillingStructured::isCompleteForIrn($existing)) {
            return $existing;
        }

        $order = $this->commerceOrderFor($invoice);
        $fromOrder = StatutoryBillingStructured::fromStored(
            $order?->billing_address_structured,
        );
        if ($fromOrder !== null && StatutoryBillingStructured::isCompleteForIrn($fromOrder)) {
            return $fromOrder;
        }

        return $this->extractStructuredFromFreeText($invoice, $order);
    }

    /**
     * @return array<string, mixed>
     */
    private function lineRemediation(StatutoryInvoice $invoice, StatutoryInvoiceItem $item): array
    {
        $changes = [];
        $hsn = is_string($item->hsn_sac) ? trim($item->hsn_sac) : '';
        $profile = $this->serviceStatutory->profileForInvoiceItem($invoice, $item);

        if ($profile !== null) {
            if ($hsn === '998314' || $hsn === '') {
                $changes['hsn_sac'] = $profile->sac;
            }
            if ($item->uqc === null || trim((string) $item->uqc) === '') {
                $changes['uqc'] = $profile->uqc;
            }
        } elseif ($hsn !== '' && ! str_starts_with($hsn, '99')) {
            $catalogUqc = $this->catalogUqcForSku($item->sku);
            if ($catalogUqc !== null && ($item->uqc === null || trim((string) $item->uqc) === '')) {
                $changes['uqc'] = $catalogUqc;
            }
        }

        return $changes;
    }

    private function catalogUqcForSku(?string $sku): ?string
    {
        $sku = is_string($sku) ? trim($sku) : '';
        if ($sku === '') {
            return null;
        }

        $product = InventoryProduct::query()->where('sku', $sku)->first();
        $uqc = $product?->uqc;

        return is_string($uqc) && trim($uqc) !== '' ? strtoupper(trim($uqc)) : null;
    }

    private function commerceOrderFor(StatutoryInvoice $invoice): ?CommerceOrder
    {
        if ((string) $invoice->source_type !== StatutoryInvoiceSourceType::CommerceOrder->value) {
            return null;
        }

        return CommerceOrder::query()
            ->where('statutory_invoice_id', $invoice->id)
            ->first()
            ?? CommerceOrder::query()
                ->where('channel', $invoice->channel)
                ->where('source_id', $invoice->source_id)
                ->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractStructuredFromFreeText(StatutoryInvoice $invoice, ?CommerceOrder $order): ?array
    {
        $address = is_string($invoice->billing_address) ? trim($invoice->billing_address) : '';
        if ($address === '') {
            return null;
        }

        if (! preg_match('/\b(\d{6})\b/', $address, $matches)) {
            return null;
        }

        $pin = $matches[1];
        $state = is_string($order?->billing_state) ? trim($order->billing_state) : '';
        if ($state === '' && is_string($invoice->place_of_supply_state)) {
            $state = trim($invoice->place_of_supply_state);
        }
        if ($state === '') {
            return null;
        }

        $city = $this->inferCityFromAddress($address, $state);
        if ($city === null) {
            return null;
        }

        return StatutoryBillingStructured::fromParts(
            line1: $address,
            city: $city,
            state: $state,
            pincode: $pin,
        );
    }

    private function inferCityFromAddress(string $address, string $state): ?string
    {
        $segments = array_values(array_filter(array_map('trim', explode(',', $address))));
        foreach (array_reverse($segments) as $segment) {
            if (preg_match('/\b\d{6}\b/', $segment)) {
                continue;
            }
            if (strcasecmp($segment, $state) === 0) {
                continue;
            }
            if (strlen($segment) >= 3 && strlen($segment) <= 64) {
                return $segment;
            }
        }

        return null;
    }
}
