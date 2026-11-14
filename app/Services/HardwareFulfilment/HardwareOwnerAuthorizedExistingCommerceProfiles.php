<?php

namespace App\Services\HardwareFulfilment;

/**
 * Immutable Owner allowlist for opening hardware fulfilment from existing paid Commerce
 * without replaying Box channel ingest. Each profile is an exact identity contract.
 */
final class HardwareOwnerAuthorizedExistingCommerceProfiles
{
    public const PURPOSE = 'owner-authorized-existing-commerce-fulfilment';

    /**
     * Production-verified RBP2138 gap (P-14-57). No other RBP ids are permitted.
     *
     * @var array<string, array{
     *   commerce_order_no: string,
     *   payment_reference: string,
     *   support_order_id: int,
     *   production_commerce_order_id: int,
     *   lines: list<array{model_id: int, qty: int, catalog_sku: string}>
     * }>
     */
    public const PROFILES = [
        'RBP2138' => [
            'commerce_order_no' => 'CO-022155',
            'payment_reference' => '6998469575',
            'support_order_id' => 73007,
            'production_commerce_order_id' => 22155,
            'lines' => [
                ['model_id' => 946, 'qty' => 1, 'catalog_sku' => 'RBMFS110L1'],
            ],
        ],
    ];

    public static function has(string $sourceId): bool
    {
        return array_key_exists(strtoupper(trim($sourceId)), self::PROFILES);
    }

    /**
     * @return array{
     *   commerce_order_no: string,
     *   payment_reference: string,
     *   support_order_id: int,
     *   production_commerce_order_id: int,
     *   lines: list<array{model_id: int, qty: int, catalog_sku: string}>
     * }|null
     */
    public static function profile(string $sourceId): ?array
    {
        $key = strtoupper(trim($sourceId));

        return self::PROFILES[$key] ?? null;
    }
}
