<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\InventoryProduct;
use App\Models\ServiceOrder;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use App\Support\StatutoryInvoice\StatutoryBillingStructured;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

    /**
     * Desk Service POS exceptions (P-22-09-12).
     *
     * @var list<int>
     */
    public const DESK_SERVICE_EXCEPTION_INVOICE_IDS = [
        4721,
    ];

    public function __construct(
        private readonly ServiceStatutoryClassification $serviceStatutory,
        private readonly PlaceOfSupplyResolver $placeOfSupply,
        private readonly EInvoiceInputReadiness $readiness,
        private readonly EInvoiceIrnPayloadMapper $mapper,
        private readonly ServiceSacResolver $serviceSac,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(StatutoryInvoice $invoice): array
    {
        return $this->remediate($invoice, apply: false);
    }

    /**
     * @return array<string, mixed>
     */
    public function remediate(StatutoryInvoice $invoice, bool $apply = false, ?string $actor = null): array
    {
        if (! $this->isWhitelisted((int) $invoice->id)) {
            return [
                'invoice_id' => $invoice->id,
                'applied' => false,
                'reason' => 'invoice_not_in_exception_set',
            ];
        }

        $invoice->loadMissing('items');
        $identityBefore = $this->identitySnapshot($invoice);
        $payloadBefore = $this->mapper->map($invoice);
        $readinessBefore = $this->readiness->evaluate($invoice);

        $changes = $this->buildInvoiceChanges($invoice);
        $lineChanges = $this->buildLineChanges($invoice);
        $afterStructured = $this->structuredAfterChanges($invoice, $changes);

        $simulated = $invoice->fresh(['items']) ?? $invoice;
        if ($afterStructured !== null) {
            $simulated->billing_address_structured = $afterStructured;
        }

        $payloadAfter = $this->mapper->map($simulated);
        $readinessAfter = $this->readiness->evaluate($simulated);

        $report = [
            'invoice_id' => $invoice->id,
            'invoice_number' => (string) $invoice->invoice_number,
            'applied' => false,
            'dry_run' => ! $apply,
            'before' => [
                'billing_address_structured' => $invoice->billing_address_structured,
                'identity' => $identityBefore,
                'payload_gaps' => $payloadBefore->gaps,
                'is_submittable' => $payloadBefore->isSubmittable(),
                'readiness' => [
                    'ready' => $readinessBefore->ready,
                    'blocked_reasons' => $readinessBefore->blockedReasonLines(),
                ],
            ],
            'after' => [
                'billing_address_structured' => $afterStructured,
                'payload_gaps' => $payloadAfter->gaps,
                'is_submittable' => $payloadAfter->isSubmittable(),
                'readiness' => [
                    'ready' => $readinessAfter->ready,
                    'blocked_reasons' => $readinessAfter->blockedReasonLines(),
                ],
            ],
            'invoice_changes' => array_keys($changes),
            'line_changes' => $lineChanges,
            'fields_preserved' => array_keys($identityBefore),
        ];

        if (! $apply) {
            return $report;
        }

        if ($changes === [] && $lineChanges === []) {
            $report['reason'] = 'no_changes_required';

            return $report;
        }

        DB::transaction(function () use ($invoice, $changes, $lineChanges, $identityBefore): void {
            if ($changes !== []) {
                DB::table('statutory_invoices')->where('id', $invoice->id)->update($changes);
            }

            foreach ($lineChanges as $itemId => $itemUpdate) {
                if ($itemUpdate !== []) {
                    DB::table('statutory_invoice_items')
                        ->where('id', $itemId)
                        ->update($itemUpdate);
                }
            }

            $fresh = StatutoryInvoice::query()->with('items')->findOrFail($invoice->id);
            $identityAfter = $this->identitySnapshot($fresh);
            if ($identityBefore !== $identityAfter) {
                throw new \RuntimeException('Remediation would have changed immutable invoice identity.');
            }
        });

        $fresh = StatutoryInvoice::query()->with('items')->findOrFail($invoice->id);
        $payloadApplied = $this->mapper->map($fresh);
        $readinessApplied = $this->readiness->evaluate($fresh);

        $report['applied'] = true;
        $report['after']['billing_address_structured'] = $fresh->billing_address_structured;
        $report['after']['payload_gaps'] = $payloadApplied->gaps;
        $report['after']['is_submittable'] = $payloadApplied->isSubmittable();
        $report['after']['readiness'] = [
            'ready' => $readinessApplied->ready,
            'blocked_reasons' => $readinessApplied->blockedReasonLines(),
        ];

        Log::info('statutory_invoice_exception_remediation_applied', [
            'invoice_id' => $invoice->id,
            'invoice_number' => (string) $invoice->invoice_number,
            'actor' => $actor ?? 'system',
            'timestamp' => now()->toIso8601String(),
            'before' => $report['before'],
            'after' => $report['after'],
            'invoice_changes' => $report['invoice_changes'],
            'line_changes' => $report['line_changes'],
        ]);

        return $report;
    }

    private function isWhitelisted(int $invoiceId): bool
    {
        /** @var list<int> $extra */
        $extra = config('statutory_invoices.einvoice.exception_remediation_extra_invoice_ids', []);

        return in_array($invoiceId, self::EXCEPTION_INVOICE_IDS, true)
            || in_array($invoiceId, self::DESK_SERVICE_EXCEPTION_INVOICE_IDS, true)
            || in_array($invoiceId, $extra, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function identitySnapshot(StatutoryInvoice $invoice): array
    {
        return [
            'invoice_number' => (string) $invoice->invoice_number,
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'channel' => (string) $invoice->channel?->value ?? (string) $invoice->channel,
            'source_type' => (string) $invoice->source_type,
            'source_id' => (string) $invoice->source_id,
            'seller_gstin' => (string) $invoice->seller_gstin,
            'seller_name' => (string) $invoice->seller_name,
            'buyer_name' => (string) $invoice->buyer_name,
            'buyer_gstin' => (string) $invoice->buyer_gstin,
            'place_of_supply_state' => (string) $invoice->place_of_supply_state,
            'place_of_supply_state_code' => (string) $invoice->place_of_supply_state_code,
            'taxable_value' => (string) $invoice->taxable_value,
            'cgst' => (string) $invoice->cgst,
            'sgst' => (string) $invoice->sgst,
            'igst' => (string) $invoice->igst,
            'invoice_value' => (string) $invoice->invoice_value,
            'payment_reference' => $invoice->payment_reference,
            'status' => (string) $invoice->status?->value ?? (string) $invoice->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildInvoiceChanges(StatutoryInvoice $invoice): array
    {
        $changes = [];
        $structured = $this->resolveStructuredBilling($invoice);
        if ($structured !== null) {
            $existing = StatutoryBillingStructured::fromStored($invoice->billing_address_structured);
            $encoded = json_encode($structured, JSON_UNESCAPED_UNICODE);
            if ($existing !== $structured && is_string($encoded)) {
                $changes['billing_address_structured'] = $encoded;
            }
        }

        if ($this->isDeskServiceBillingOnly($invoice)) {
            return $changes;
        }

        $pos = $this->placeOfSupply->resolveForInvoice($invoice->fresh(['items']));
        if ($pos->isResolvable()) {
            $changes['place_of_supply_state'] = $pos->state;
            $changes['place_of_supply_state_code'] = $pos->stateCode;
            $changes['place_of_supply_source'] = $pos->source;
        }

        return $changes;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildLineChanges(StatutoryInvoice $invoice): array
    {
        if ($this->isDeskServiceBillingOnly($invoice)) {
            return [];
        }

        $lineChanges = [];
        foreach ($invoice->items as $item) {
            $lineChanges[(int) $item->id] = $this->lineRemediation($invoice, $item);
        }

        return $lineChanges;
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>|null
     */
    private function structuredAfterChanges(StatutoryInvoice $invoice, array $changes): ?array
    {
        if (! array_key_exists('billing_address_structured', $changes)) {
            return StatutoryBillingStructured::fromStored($invoice->billing_address_structured);
        }

        $decoded = json_decode((string) $changes['billing_address_structured'], true);

        return is_array($decoded) ? $decoded : null;
    }

    private function isDeskServiceBillingOnly(StatutoryInvoice $invoice): bool
    {
        return in_array((int) $invoice->id, self::DESK_SERVICE_EXCEPTION_INVOICE_IDS, true);
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

        $commerce = $this->commerceOrderFor($invoice);
        $fromCommerce = StatutoryBillingStructured::fromStored(
            $commerce?->billing_address_structured,
        );
        if ($fromCommerce !== null && StatutoryBillingStructured::isCompleteForIrn($fromCommerce)) {
            return $fromCommerce;
        }

        $serviceOrder = $this->serviceOrderFor($invoice);
        $fromServiceOrder = StatutoryBillingStructured::fromStored(
            $serviceOrder?->billing_address_structured,
        );
        if ($fromServiceOrder !== null && StatutoryBillingStructured::isCompleteForIrn($fromServiceOrder)) {
            return $fromServiceOrder;
        }

        return $this->extractStructuredFromFreeText($invoice, $commerce, $serviceOrder);
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

    private function serviceOrderFor(StatutoryInvoice $invoice): ?ServiceOrder
    {
        if ((string) $invoice->source_type !== StatutoryInvoiceSourceType::ServiceOrder->value) {
            return null;
        }

        return ServiceOrder::query()
            ->where('statutory_invoice_id', $invoice->id)
            ->first()
            ?? ServiceOrder::query()
                ->where('order_number', $invoice->source_id)
                ->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractStructuredFromFreeText(
        StatutoryInvoice $invoice,
        ?CommerceOrder $commerce,
        ?ServiceOrder $serviceOrder,
    ): ?array {
        $address = is_string($invoice->billing_address) ? trim($invoice->billing_address) : '';
        if ($address === '') {
            return null;
        }

        if (! preg_match('/\b(\d{6})\b/', $address, $matches)) {
            return null;
        }

        $pin = $matches[1];
        $state = is_string($commerce?->billing_state) ? trim($commerce->billing_state) : '';
        if ($state === '' && is_string($serviceOrder?->billing_state)) {
            $state = trim($serviceOrder->billing_state);
        }
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
        $normalized = str_replace(["\r\n", "\r"], "\n", $address);
        $segments = array_values(array_filter(array_map('trim', preg_split('/[,\n]+/', $normalized) ?: [])));
        foreach (array_reverse($segments) as $segment) {
            if (preg_match('/^(.+?)\s*-\s*(\d{6})\s*$/', $segment, $cityPin)) {
                $candidate = trim($cityPin[1]);
                if (strcasecmp($candidate, $state) !== 0 && strlen($candidate) >= 3 && strlen($candidate) <= 64) {
                    return $candidate;
                }
            }
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
