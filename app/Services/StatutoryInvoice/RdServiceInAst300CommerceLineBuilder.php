<?php

namespace App\Services\StatutoryInvoice;

use App\Models\CommerceOrder;
use App\Models\Order;
use App\Services\StatutoryInvoice\Data\GstComponentSplit;
use Illuminate\Validation\ValidationException;

/**
 * Builds authoritative AST300 rdservice.in commerce lines from support payment facts.
 */
final class RdServiceInAst300CommerceLineBuilder
{
    private const GST_RATE = 18.0;

    public function __construct(
        private readonly GstSplitService $gstSplit,
        private readonly RdServiceInAst300ServiceLineDescriptor $descriptions,
    ) {}

    /**
     * @return array{
     *     billable: array<string, mixed>,
     *     included: array<string, mixed>,
     *     header: array{taxable_value: float, tax_total: float, order_value: float}
     * }
     */
    public function build(CommerceOrder $commerce, Order $support): array
    {
        $metadata = is_array($commerce->metadata) ? $commerce->metadata : [];
        $durationLabel = trim((string) ($metadata['rd_service_name'] ?? ''));
        if ($durationLabel === '') {
            throw ValidationException::withMessages([
                'commerce_order' => 'AST300 renewal metadata is missing rd_service_name.',
            ]);
        }

        $serial = strtoupper(trim((string) ($support->serial_number ?? $metadata['serial_no'] ?? '')));
        if ($serial === '') {
            throw ValidationException::withMessages([
                'commerce_order' => 'AST300 renewal serial number is missing.',
            ]);
        }

        $gross = round((float) $support->payment_amount, 2);
        if ($gross <= 0) {
            throw ValidationException::withMessages([
                'support_order' => 'AST300 renewal requires a positive verified payment amount.',
            ]);
        }

        $taxable = round($gross / (1 + (self::GST_RATE / 100)), 2);
        $taxTotal = round($gross - $taxable, 2);
        $split = $this->split($commerce, $taxable, $taxTotal);

        $billable = [
            'description' => $this->descriptions->billableDescription($serial, $durationLabel),
            'hsn_sac' => '998313',
            'qty' => 1,
            'unit_price' => $taxable,
            'gst_percentage' => self::GST_RATE,
            'taxable_value' => $taxable,
            'tax_total' => $taxTotal,
            'line_total' => $gross,
            'igst' => $split->igst,
            'cgst' => $split->cgst,
            'sgst' => $split->sgst,
        ];

        $included = [
            'description' => RdServiceInAst300ServiceLineDescriptor::INCLUDED_SUPPORT,
            'hsn_sac' => '998313',
            'qty' => 1,
            'unit_price' => 0.0,
            'gst_percentage' => self::GST_RATE,
            'taxable_value' => 0.0,
            'tax_total' => 0.0,
            'line_total' => 0.0,
            'igst' => 0.0,
            'cgst' => 0.0,
            'sgst' => 0.0,
        ];

        return [
            'billable' => $billable,
            'included' => $included,
            'header' => [
                'taxable_value' => $taxable,
                'tax_total' => $taxTotal,
                'order_value' => $gross,
            ],
        ];
    }

    private function split(CommerceOrder $commerce, float $taxable, float $taxTotal): GstComponentSplit
    {
        $sellerCode = BuyerGstin::stateCode((string) $commerce->seller_gstin);
        if ($sellerCode === null) {
            throw ValidationException::withMessages([
                'commerce_order' => 'AST300 renewal commerce snapshot is missing seller GSTIN.',
            ]);
        }

        $placeOfSupply = trim((string) ($commerce->place_of_supply_state ?? ''));
        if ($placeOfSupply === '') {
            throw ValidationException::withMessages([
                'commerce_order' => 'AST300 renewal commerce snapshot is missing place of supply.',
            ]);
        }

        return $this->gstSplit->splitLine(
            $sellerCode,
            $placeOfSupply,
            self::GST_RATE,
            $taxable,
            $taxTotal,
            exclusivePaisaTolerance: 1,
        );
    }
}
