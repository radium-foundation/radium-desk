<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\StatutoryInvoiceChannel;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use App\Services\StatutoryInvoice\Data\ServiceStatutoryProfile;

/**
 * Owner-approved service SAC / IsServc / UQC from explicit config.
 * Does not default goods to PCS or services to PCS/NOS.
 */
final class ServiceStatutoryClassification
{
    public function profileForCommerceLine(
        ?string $channel,
        ?string $sku,
        ?string $description,
        ?string $hsnSac,
        ?int $amcId = null,
        ?string $shippingLineKind = null,
    ): ?ServiceStatutoryProfile {
        if ($shippingLineKind === 'physical_merchandise') {
            return null;
        }

        $resolver = app(ServiceSacResolver::class);
        $resolvedSac = $resolver->resolve($channel, $sku, $description, $hsnSac, $amcId, $shippingLineKind);
        if ($resolvedSac === null) {
            return null;
        }

        return $this->profileForSacAndChannel($channel, $resolvedSac, $sku, $description, $amcId);
    }

    public function profileForInvoiceItem(StatutoryInvoice $invoice, StatutoryInvoiceItem $item): ?ServiceStatutoryProfile
    {
        $channel = $invoice->channel;
        $channelValue = $channel instanceof StatutoryInvoiceChannel ? $channel->value : (string) $channel;
        $hsn = is_string($item->hsn_sac) ? trim($item->hsn_sac) : '';
        $sku = is_string($item->sku) ? trim($item->sku) : '';
        $description = is_string($item->description) ? trim($item->description) : '';

        $profile = $this->profileForSacAndChannel($channelValue, $hsn, $sku, $description, null);
        if ($profile !== null) {
            return $profile;
        }

        return $this->profileForCommerceLine($channelValue, $sku, $description, $hsn);
    }

    public function profileForSacAndChannel(
        ?string $channel,
        string $sac,
        ?string $sku = null,
        ?string $description = null,
        ?int $amcId = null,
    ): ?ServiceStatutoryProfile {
        $channelValue = strtolower(trim((string) $channel));
        $sac = strtoupper(trim($sac));
        if ($channelValue === '' || $sac === '') {
            return null;
        }

        foreach ($this->services() as $key => $service) {
            if (! is_array($service)) {
                continue;
            }
            $configuredSac = strtoupper(trim((string) ($service['sac'] ?? '')));
            $aliases = $this->legacySacAliases($service);
            $sacs = array_values(array_unique(array_filter([$configuredSac, ...$aliases])));
            if (! in_array($sac, $sacs, true)) {
                continue;
            }

            $channels = $this->normalizedList($service['channels'] ?? null);
            if ($channels === [] || ! in_array($channelValue, $channels, true)) {
                continue;
            }

            if (! $this->lineMatchesService($channelValue, $sku, $description, $amcId, $service, $sac, $configuredSac)) {
                continue;
            }

            $isServc = strtoupper(trim((string) ($service['is_servc'] ?? 'Y')));
            $uqc = strtoupper(trim((string) ($service['uqc'] ?? '')));
            if ($uqc === '') {
                return null;
            }

            return new ServiceStatutoryProfile(
                sac: $configuredSac,
                isServc: $isServc === 'N' ? 'N' : 'Y',
                uqc: $uqc,
                serviceKey: (string) $key,
            );
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function services(): array
    {
        $services = config('statutory_invoices.service_sac', []);

        return is_array($services) ? $services : [];
    }

    /**
     * @param  array<string, mixed>  $service
     * @return list<string>
     */
    private function legacySacAliases(array $service): array
    {
        $aliases = [];
        foreach ($this->normalizedList($service['legacy_sac_aliases'] ?? null) as $alias) {
            $aliases[] = strtoupper($alias);
        }

        return $aliases;
    }

    /**
     * @param  array<string, mixed>  $service
     */
    private function lineMatchesService(
        string $channel,
        ?string $sku,
        ?string $description,
        ?int $amcId,
        array $service,
        string $incomingSac,
        string $configuredSac,
    ): bool {
        if ($incomingSac === $configuredSac) {
            return app(ServiceSacResolver::class)->resolve(
                $channel,
                $sku,
                $description,
                $incomingSac,
                $amcId,
            ) === $configuredSac;
        }

        return in_array($incomingSac, $this->legacySacAliases($service), true);
    }

    /**
     * @return list<string>
     */
    private function normalizedList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $out = [];
        foreach ($values as $value) {
            $normalized = strtolower(trim((string) $value));
            if ($normalized !== '') {
                $out[] = $normalized;
            }
        }

        return array_values(array_unique($out));
    }
}
