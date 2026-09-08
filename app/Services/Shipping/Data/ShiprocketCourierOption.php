<?php

namespace App\Services\Shipping\Data;

final class ShiprocketCourierOption
{
    public function __construct(
        public readonly string $courierId,
        public readonly ?string $courierName = null,
        public readonly ?string $rate = null,
        public readonly ?string $coverageCharge = null,
        public readonly ?string $estimatedDelivery = null,
        public readonly ?bool $codAvailable = null,
        public readonly ?bool $prepaidAvailable = null,
        public readonly bool $providerRecommended = false,
        public readonly ?string $courierType = null,
        public readonly ?string $mode = null,
    ) {}

    /**
     * @return array{
     *     courier_id: string,
     *     courier_name: string|null,
     *     rate: string|null,
     *     coverage_charge: string|null,
     *     estimated_delivery: string|null,
     *     cod_available: bool|null,
     *     prepaid_available: bool|null,
     *     provider_recommended: bool,
     *     courier_type: string|null,
     *     mode: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'courier_id' => $this->courierId,
            'courier_name' => $this->courierName,
            'rate' => $this->rate,
            'coverage_charge' => $this->coverageCharge,
            'estimated_delivery' => $this->estimatedDelivery,
            'cod_available' => $this->codAvailable,
            'prepaid_available' => $this->prepaidAvailable,
            'provider_recommended' => $this->providerRecommended,
            'courier_type' => $this->courierType,
            'mode' => $this->mode,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): ?self
    {
        $id = trim((string) ($row['courier_id'] ?? ''));
        if ($id === '') {
            return null;
        }

        return new self(
            courierId: $id,
            courierName: self::nullableString($row['courier_name'] ?? null),
            rate: self::nullableString($row['rate'] ?? null),
            coverageCharge: self::nullableString($row['coverage_charge'] ?? null),
            estimatedDelivery: self::nullableString($row['estimated_delivery'] ?? null),
            codAvailable: self::nullableBool($row['cod_available'] ?? null),
            prepaidAvailable: self::nullableBool($row['prepaid_available'] ?? null),
            providerRecommended: (bool) ($row['provider_recommended'] ?? false),
            courierType: self::nullableString($row['courier_type'] ?? null),
            mode: self::nullableString($row['mode'] ?? null),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private static function nullableBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (bool) $value;
    }
}
