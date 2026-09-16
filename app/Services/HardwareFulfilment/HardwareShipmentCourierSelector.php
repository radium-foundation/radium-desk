<?php

namespace App\Services\HardwareFulfilment;

/**
 * Picks one courier from a provider-returned serviceability list.
 * Does not invent courier IDs. Preferred IDs come only from config/env.
 */
final class HardwareShipmentCourierSelector
{
    /**
     * @param  list<array<string, mixed>>  $options
     * @param  list<string>  $rejectedIds
     * @param  array<string, mixed>|null  $anchor
     * @return array<string, mixed>|null
     */
    public function choose(
        array $options,
        array $rejectedIds = [],
        ?string $recommendedId = null,
        ?array $anchor = null,
        bool $allowUnrankedEligible = false,
    ): ?array {
        $rejected = [];
        foreach ($rejectedIds as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $rejected[$id] = true;
            }
        }

        $eligible = [];
        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }
            $id = trim((string) ($option['courier_id'] ?? ''));
            if ($id === '' || isset($rejected[$id])) {
                continue;
            }
            $eligible[] = $option;
        }

        if ($eligible === []) {
            return null;
        }

        foreach ($this->preferredCourierIds() as $preferredId) {
            $match = $this->optionById($eligible, $preferredId);
            if ($match !== null) {
                return $match;
            }
        }

        $anchorMode = $this->normalizedMode($anchor['mode'] ?? null);
        if ($anchorMode !== null) {
            foreach ($eligible as $option) {
                if ($this->normalizedMode($option['mode'] ?? null) === $anchorMode) {
                    return $option;
                }
            }
        }

        $recommended = $this->optionById($eligible, $recommendedId);
        if ($recommended !== null) {
            return $recommended;
        }

        if ($allowUnrankedEligible) {
            return $eligible[0];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function preferredCourierIds(): array
    {
        $raw = config('shipping.preferred_courier_ids', []);
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (! is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $id) {
            $id = trim((string) $id);
            if ($id !== '' && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param  list<array<string, mixed>>  $options
     * @return array<string, mixed>|null
     */
    public function optionById(array $options, ?string $courierId): ?array
    {
        $id = trim((string) $courierId);
        if ($id === '') {
            return null;
        }

        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }
            if (trim((string) ($option['courier_id'] ?? '')) === $id) {
                return $option;
            }
        }

        return null;
    }

    private function normalizedMode(mixed $mode): ?string
    {
        if ($mode === null || is_array($mode)) {
            return null;
        }

        $text = trim((string) $mode);

        return $text === '' ? null : $text;
    }
}
