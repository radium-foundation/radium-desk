<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\StatutoryInvoiceChannel;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;

/**
 * IsServc from Desk channel + configured SAC/SKU, not product description.
 */
final class EInvoiceServiceClassification
{
    public function isServc(StatutoryInvoice $invoice, StatutoryInvoiceItem $item): ?string
    {
        $channel = $invoice->channel;
        $hsn = is_string($item->hsn_sac) ? trim($item->hsn_sac) : '';
        $sku = is_string($item->sku) ? trim($item->sku) : '';

        if ($channel === StatutoryInvoiceChannel::RdServiceIn || $channel === StatutoryInvoiceChannel::RdServiceNet) {
            return $this->matchesConfiguredService($channel->value, $hsn, $sku) ? 'Y' : null;
        }

        if ($channel === StatutoryInvoiceChannel::DeskPos || $channel === StatutoryInvoiceChannel::RadiumBoxCom) {
            if ($hsn === '' || str_starts_with($hsn, '99')) {
                return null;
            }

            return 'N';
        }

        return null;
    }

    private function matchesConfiguredService(string $channel, string $hsn, string $sku): bool
    {
        $services = config('statutory_invoices.service_sac', []);
        if (! is_array($services)) {
            return false;
        }

        foreach ($services as $definition) {
            if (! is_array($definition)) {
                continue;
            }
            $channels = $definition['channels'] ?? [];
            if (! is_array($channels) || ! in_array($channel, $channels, true)) {
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
