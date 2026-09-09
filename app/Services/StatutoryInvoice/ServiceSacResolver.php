<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use Illuminate\Validation\ValidationException;

/**
 * Resolves an explicit per-service SAC. There is no generic default.
 *
 * RD Service and AMC are 998313. Other services keep their own configured or incoming SAC.
 */
final class ServiceSacResolver
{
    public function forCommerceItem(CommerceOrder $order, CommerceOrderItem $item): ?string
    {
        return $this->resolve(
            $this->channelValue($order->channel),
            $item->sku,
            $item->description,
            $item->hsn_sac,
            $item->amcid !== null ? (int) $item->amcid : null,
            $item->shipping_line_kind,
        );
    }

    public function resolve(
        ?string $channel,
        ?string $sku,
        ?string $description,
        ?string $incomingHsnSac,
        ?int $amcId = null,
        ?string $shippingLineKind = null,
    ): ?string {
        if ($shippingLineKind === 'physical_merchandise') {
            return $this->normalizeSac($incomingHsnSac);
        }
        $matched = [];
        foreach ($this->services() as $key => $service) {
            if (! is_array($service) || ! $this->matches($channel, $sku, $description, $amcId, $service)) {
                continue;
            }

            $sac = $this->normalizeSac($service['sac'] ?? null);
            if ($sac === null) {
                throw ValidationException::withMessages([
                    'sac' => 'Service SAC mapping "'.$key.'" is missing an explicit SAC.',
                ]);
            }
            $matched[$key] = $sac;
        }

        if (count($matched) > 1) {
            throw ValidationException::withMessages([
                'sac' => 'Line matched multiple service SAC mappings ('.implode(', ', array_keys($matched)).'). Mapping fails closed.',
            ]);
        }

        if (count($matched) === 1) {
            return array_values($matched)[0];
        }

        return $this->normalizeSac($incomingHsnSac);
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
     */
    private function matches(?string $channel, ?string $sku, ?string $description, ?int $amcId, array $service): bool
    {
        $channels = $this->normalizedList($service['channels'] ?? null);
        $channelValue = strtolower(trim((string) $channel));
        if ($channels === [] || $channelValue === '' || ! in_array($channelValue, $channels, true)) {
            return false;
        }

        $skuValue = strtolower(trim((string) $sku));
        $skus = $this->normalizedList($service['skus'] ?? null);
        if ($skuValue !== '' && in_array($skuValue, $skus, true)) {
            return true;
        }

        $haystack = strtolower(trim((string) $description));
        foreach ($this->normalizedList($service['description_needles'] ?? null) as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        if (($service['match_amcid'] ?? false) === true && $amcId !== null && $amcId > 0) {
            return true;
        }

        return false;
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

    private function normalizeSac(mixed $value): ?string
    {
        $sac = strtoupper(preg_replace('/\s+/', '', (string) $value) ?? '');

        return $sac !== '' ? $sac : null;
    }

    private function channelValue(mixed $channel): ?string
    {
        if ($channel instanceof StatutoryInvoiceChannel) {
            return $channel->value;
        }

        $value = trim((string) $channel);

        return $value !== '' ? $value : null;
    }
}
