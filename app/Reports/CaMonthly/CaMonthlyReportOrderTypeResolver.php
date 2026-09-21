<?php

namespace App\Reports\CaMonthly;

use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use Illuminate\Support\Collection;

final class CaMonthlyReportOrderTypeResolver
{
    public function __construct(
        private readonly CaMonthlyReportCommerceLineKindIndex $commerceLineKindIndex,
    ) {}

    /**
     * @param  Collection<int, StatutoryInvoice>  $invoices
     * @return array<int, ?string>
     */
    public function resolveForInvoices(Collection $invoices): array
    {
        $lineKindsByInvoiceId = $this->commerceLineKindIndex->lineKindsByInvoiceId($invoices);
        $resolved = [];

        foreach ($invoices as $invoice) {
            $items = $invoice->relationLoaded('items')
                ? $invoice->items
                : $invoice->items()->get();

            $resolved[$invoice->id] = $this->resolveForInvoice(
                $invoice,
                $items,
                $lineKindsByInvoiceId[$invoice->id] ?? [],
            );
        }

        return $resolved;
    }

    /**
     * @param  Collection<int, StatutoryInvoiceItem>  $items
     * @param  array<int, ?string>  $lineKindsByLineNo
     */
    public function resolveForInvoice(
        StatutoryInvoice $invoice,
        Collection $items,
        array $lineKindsByLineNo = [],
    ): ?string {
        if ($items->isEmpty()) {
            return $this->channelFallback($invoice);
        }

        $hasHardware = false;
        $hasService = false;

        foreach ($items as $item) {
            if ($this->isHardwareLine($invoice, $item, $lineKindsByLineNo)) {
                $hasHardware = true;
            } elseif ($this->isServiceLine($invoice, $item, $lineKindsByLineNo)) {
                $hasService = true;
            }
        }

        if ($hasHardware && $hasService) {
            return CaMonthlyReportOrderType::BUNDLED;
        }

        if ($hasService) {
            return CaMonthlyReportOrderType::SERVICE;
        }

        if ($hasHardware) {
            return CaMonthlyReportOrderType::HARDWARE;
        }

        return $this->channelFallback($invoice);
    }

    /**
     * @param  array<int, ?string>  $lineKindsByLineNo
     */
    private function isServiceLine(
        StatutoryInvoice $invoice,
        StatutoryInvoiceItem $item,
        array $lineKindsByLineNo,
    ): bool {
        $lineKind = $lineKindsByLineNo[(int) $item->line_no] ?? null;
        if ($lineKind === HardwareFulfilmentEligibility::PHYSICAL_LINE_KIND) {
            return false;
        }

        if ($this->isServiceSac($item->hsn_sac)) {
            return true;
        }

        if ($invoice->channel === StatutoryInvoiceChannel::DeskService) {
            return true;
        }

        $sourceType = $invoice->source_type;
        if ($sourceType === StatutoryInvoiceSourceType::ServiceOrder
            || (is_string($sourceType) && $sourceType === StatutoryInvoiceSourceType::ServiceOrder->value)) {
            return true;
        }

        if ($lineKind !== null && $lineKind !== HardwareFulfilmentEligibility::PHYSICAL_LINE_KIND) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<int, ?string>  $lineKindsByLineNo
     */
    private function isHardwareLine(
        StatutoryInvoice $invoice,
        StatutoryInvoiceItem $item,
        array $lineKindsByLineNo,
    ): bool {
        $lineKind = $lineKindsByLineNo[(int) $item->line_no] ?? null;
        if ($lineKind === HardwareFulfilmentEligibility::PHYSICAL_LINE_KIND) {
            return true;
        }

        if ($this->isServiceSac($item->hsn_sac)) {
            return false;
        }

        if ($invoice->channel === StatutoryInvoiceChannel::DeskPos) {
            return true;
        }

        $sourceType = $invoice->source_type;
        if ($sourceType === StatutoryInvoiceSourceType::InventorySale
            || (is_string($sourceType) && $sourceType === StatutoryInvoiceSourceType::InventorySale->value)) {
            return true;
        }

        $hsn = $this->nullableString($item->hsn_sac);
        if ($hsn !== null && ! $this->isServiceSac($hsn)) {
            return true;
        }

        return false;
    }

    private function channelFallback(StatutoryInvoice $invoice): ?string
    {
        return match ($invoice->channel) {
            StatutoryInvoiceChannel::DeskPos => CaMonthlyReportOrderType::HARDWARE,
            StatutoryInvoiceChannel::DeskService => CaMonthlyReportOrderType::SERVICE,
            default => null,
        };
    }

    private function isServiceSac(mixed $hsnSac): bool
    {
        $hsn = $this->nullableString($hsnSac);
        if ($hsn === null) {
            return false;
        }

        return preg_match('/^99\d{4}$/', $hsn) === 1;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
