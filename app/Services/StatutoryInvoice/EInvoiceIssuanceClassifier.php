<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\EInvoiceIssuanceKind;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;

/**
 * Invoice-level hardware/service kind for issuance policy.
 * Separate from NIC IsServc payload classification.
 */
final class EInvoiceIssuanceClassifier
{
    public function classify(StatutoryInvoice $invoice): EInvoiceIssuanceKind
    {
        $invoice->loadMissing('items');
        if ($invoice->items->isEmpty()) {
            return EInvoiceIssuanceKind::Unknown;
        }

        $sawHardware = false;
        $sawService = false;
        foreach ($invoice->items as $item) {
            $kind = $this->classifyLine($invoice, $item);
            if ($kind === EInvoiceIssuanceKind::Unknown) {
                return EInvoiceIssuanceKind::Unknown;
            }
            if ($kind === EInvoiceIssuanceKind::Hardware) {
                $sawHardware = true;
            }
            if ($kind === EInvoiceIssuanceKind::Service) {
                $sawService = true;
            }
        }

        if ($sawHardware && $sawService) {
            return EInvoiceIssuanceKind::Mixed;
        }
        if ($sawHardware) {
            return EInvoiceIssuanceKind::Hardware;
        }
        if ($sawService) {
            return EInvoiceIssuanceKind::Service;
        }

        return EInvoiceIssuanceKind::Unknown;
    }

    public function classifyLine(StatutoryInvoice $invoice, StatutoryInvoiceItem $item): EInvoiceIssuanceKind
    {
        $channel = $invoice->channel;
        $hsn = is_string($item->hsn_sac) ? trim($item->hsn_sac) : '';
        $sku = is_string($item->sku) ? trim($item->sku) : '';

        if ($this->isConfiguredService($channel, $hsn, $sku) || $this->isServiceChapter($hsn)) {
            return EInvoiceIssuanceKind::Service;
        }

        if ($this->isVerifiedHardwareChannel($channel) && $hsn !== '') {
            return EInvoiceIssuanceKind::Hardware;
        }

        return EInvoiceIssuanceKind::Unknown;
    }

    private function isServiceChapter(string $hsn): bool
    {
        return $hsn !== '' && str_starts_with($hsn, '99');
    }

    private function isVerifiedHardwareChannel(mixed $channel): bool
    {
        return $channel === StatutoryInvoiceChannel::DeskPos
            || $channel === StatutoryInvoiceChannel::RadiumBoxCom;
    }

    private function isConfiguredService(mixed $channel, string $hsn, string $sku): bool
    {
        if (! $channel instanceof StatutoryInvoiceChannel) {
            return false;
        }

        $services = config('statutory_invoices.service_sac', []);
        if (! is_array($services)) {
            return false;
        }

        foreach ($services as $definition) {
            if (! is_array($definition)) {
                continue;
            }
            $channels = $definition['channels'] ?? [];
            if (! is_array($channels) || ! in_array($channel->value, $channels, true)) {
                continue;
            }
            $sac = trim((string) ($definition['sac'] ?? ''));
            if ($sac !== '' && $hsn === $sac) {
                return true;
            }
            $skus = $definition['skus'] ?? [];
            if (is_array($skus) && $sku !== '' && in_array($sku, $skus, true)) {
                return true;
            }
        }

        return false;
    }
}
