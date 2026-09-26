<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\Order;
use App\Support\BusinessOrderId;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Repairs support-only AST300 rdservice.in commerce snapshots before statutory mint.
 */
final class RdServiceInAst300CommerceSnapshotService
{
    public const ATTEMPTS = 5;

    public function __construct(
        private readonly Ast300L1ProductIdentity $productIdentity,
        private readonly StatutoryInvoiceCommerceBillableLines $billableLines,
        private readonly RdServiceInAst300CommerceLineBuilder $lineBuilder,
    ) {}

    public function repairSupportOnlyCommerceIfNeeded(CommerceOrder $commerce, Order $support): CommerceOrder
    {
        if (! $this->shouldAttemptRepair($commerce, $support)) {
            return $commerce->loadMissing('items');
        }

        return DB::transaction(function () use ($commerce, $support): CommerceOrder {
            $locked = CommerceOrder::query()
                ->whereKey($commerce->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw ValidationException::withMessages([
                    'commerce_order' => 'AST300 commerce order could not be loaded for repair.',
                ]);
            }

            $locked->load('items');
            if (! $this->shouldAttemptRepair($locked, $support)) {
                return $locked;
            }

            $built = $this->lineBuilder->build($locked, $support);
            $includedItem = $locked->items->first(
                fn (CommerceOrderItem $item): bool => $this->isIncludedSupportLine($item),
            );
            $billableItem = $locked->items->first(
                fn (CommerceOrderItem $item): bool => ! $this->isIncludedSupportLine($item),
            );

            $promotedIncludedToBillable = false;

            if ($billableItem !== null) {
                $this->updateBillableItem($billableItem, $built['billable']);
            } elseif ($includedItem !== null) {
                $this->updateBillableItem($includedItem, $built['billable']);
                $promotedIncludedToBillable = true;
                CommerceOrderItem::query()->create([
                    'commerce_order_id' => $locked->id,
                    'line_no' => 2,
                    ...$built['included'],
                ]);
            } else {
                CommerceOrderItem::query()->create([
                    'commerce_order_id' => $locked->id,
                    'line_no' => 1,
                    ...$built['billable'],
                ]);
                CommerceOrderItem::query()->create([
                    'commerce_order_id' => $locked->id,
                    'line_no' => 2,
                    ...$built['included'],
                ]);
            }

            if (! $promotedIncludedToBillable
                && $includedItem !== null
                && (int) $includedItem->line_no !== 2) {
                $includedItem->forceFill([
                    'line_no' => 2,
                    'description' => RdServiceInAst300ServiceLineDescriptor::INCLUDED_SUPPORT,
                    'unit_price' => 0,
                    'taxable_value' => 0,
                    'tax_total' => 0,
                    'line_total' => 0,
                    'igst' => 0,
                    'cgst' => 0,
                    'sgst' => 0,
                ])->save();
            }

            $locked->forceFill([
                'taxable_value' => $built['header']['taxable_value'],
                'tax_total' => $built['header']['tax_total'],
                'order_value' => $built['header']['order_value'],
                'invoice_eligible' => true,
            ])->save();

            return $locked->fresh(['items']) ?? $locked;
        }, self::ATTEMPTS);
    }

    private function shouldAttemptRepair(CommerceOrder $commerce, Order $support): bool
    {
        if ($commerce->channel !== StatutoryInvoiceChannel::RdServiceIn) {
            return false;
        }

        if (! BusinessOrderId::isRdServiceInService((string) $commerce->source_id)) {
            return false;
        }

        if (! $this->productIdentity->matches($support, $commerce)) {
            return false;
        }

        if ($commerce->statutory_invoice_id !== null) {
            return false;
        }

        if ($this->billableLines->hasAny($commerce)) {
            return false;
        }

        return $this->hasVerifiedPayment($support);
    }

    private function hasVerifiedPayment(Order $support): bool
    {
        $amount = round((float) ($support->payment_amount ?? 0), 2);
        if ($amount <= 0) {
            return false;
        }

        foreach ([$support->cashfree_payment_id, $support->transaction_id] as $reference) {
            if (is_string($reference) && trim($reference) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function updateBillableItem(CommerceOrderItem $item, array $line): void
    {
        $item->forceFill([
            'line_no' => 1,
            'description' => $line['description'],
            'hsn_sac' => $line['hsn_sac'],
            'qty' => $line['qty'],
            'unit_price' => $line['unit_price'],
            'gst_percentage' => $line['gst_percentage'],
            'taxable_value' => $line['taxable_value'],
            'tax_total' => $line['tax_total'],
            'line_total' => $line['line_total'],
            'igst' => $line['igst'],
            'cgst' => $line['cgst'],
            'sgst' => $line['sgst'],
        ])->save();
    }

    private function isIncludedSupportLine(CommerceOrderItem $item): bool
    {
        return str_contains(strtolower(trim((string) $item->description)), 'rd technical support');
    }
}
