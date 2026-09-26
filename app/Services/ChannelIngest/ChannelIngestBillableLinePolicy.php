<?php

namespace App\Services\ChannelIngest;

use App\Services\ChannelIngest\Data\ChannelOrderLineDraft;
use App\Services\StatutoryInvoice\StatutoryInvoiceCommerceLinePresentation;

/**
 * Applies statutory billable-line suppression rules to channel ingest drafts.
 */
final class ChannelIngestBillableLinePolicy
{
    public function __construct(
        private readonly StatutoryInvoiceCommerceLinePresentation $presentation,
    ) {}

    /**
     * @param  iterable<int, ChannelOrderLineDraft>  $lines
     */
    public function hasExportableBillableLine(iterable $lines): bool
    {
        foreach ($lines as $line) {
            if ($this->isExportableBillableDraft($line)) {
                return true;
            }
        }

        return false;
    }

    public function isExportableBillableDraft(ChannelOrderLineDraft $line): bool
    {
        if ($line->shippingLineKind === 'physical_merchandise') {
            return true;
        }

        if ($this->hasMaterialValue($line)) {
            return true;
        }

        return ! $this->isUnselectedOptionalAddOn($line);
    }

    private function hasMaterialValue(ChannelOrderLineDraft $line): bool
    {
        foreach ([$line->taxableValue, $line->taxTotal, $line->lineTotal, $line->unitPrice] as $value) {
            if ($value !== null && abs((float) $value) >= 0.005) {
                return true;
            }
        }

        return false;
    }

    private function isUnselectedOptionalAddOn(ChannelOrderLineDraft $line): bool
    {
        $description = strtolower(trim($line->description));

        if ($description === 'not required') {
            return true;
        }

        if (str_contains($description, 'rd technical support')) {
            return true;
        }

        if (str_contains($description, 'call back') && str_contains($description, 'not required')) {
            return true;
        }

        return false;
    }
}
