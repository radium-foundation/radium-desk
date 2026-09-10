<?php

namespace App\Support;

/**
 * Longest-prefix-first business order ID classification.
 * Historical IDs remain valid; new namespaces are explicit.
 */
final class BusinessOrderId
{
    /** @var list<array{prefix:string,kind:string,owner:string,hardware:bool}> */
    private const RULES = [
        ['prefix' => 'RBP', 'kind' => 'product', 'owner' => 'radiumbox.com', 'hardware' => true],
        ['prefix' => 'RDP', 'kind' => 'product', 'owner' => 'rdservice.in', 'hardware' => false],
        ['prefix' => 'RNP', 'kind' => 'product', 'owner' => 'rdservice.net', 'hardware' => false],
        ['prefix' => 'RSP', 'kind' => 'product', 'owner' => 'radiumsign.com', 'hardware' => false],
        ['prefix' => 'RBX', 'kind' => 'gateway', 'owner' => 'radiumbox.com', 'hardware' => false],
        ['prefix' => 'RDE', 'kind' => 'product', 'owner' => 'radiumbox.com', 'hardware' => true],
        ['prefix' => 'RDS', 'kind' => 'service', 'owner' => 'radiumsign.com', 'hardware' => false],
        ['prefix' => 'RIN', 'kind' => 'product', 'owner' => 'rdservice.in', 'hardware' => true],
        ['prefix' => 'RB', 'kind' => 'service', 'owner' => 'radiumbox.com', 'hardware' => false],
        ['prefix' => 'RD', 'kind' => 'service', 'owner' => 'rdservice.in', 'hardware' => false],
        ['prefix' => 'RN', 'kind' => 'service', 'owner' => 'rdservice.net', 'hardware' => false],
        ['prefix' => 'RS', 'kind' => 'service', 'owner' => 'radiumsign.com', 'hardware' => false],
        ['prefix' => 'RA', 'kind' => 'service', 'owner' => 'rdservice.net', 'hardware' => false],
        ['prefix' => 'RC', 'kind' => 'service', 'owner' => 'radiumsign.com', 'hardware' => false],
    ];

    /**
     * @return array{prefix:string,kind:string,owner:string,hardware:bool,id:string}|null
     */
    public static function parse(?string $id): ?array
    {
        if (! is_string($id) || trim($id) === '') {
            return null;
        }

        $trimmed = strtoupper(trim($id));
        foreach (self::RULES as $rule) {
            $prefix = $rule['prefix'];
            if (preg_match('/^'.preg_quote($prefix, '/').'[0-9A-Z]{1,61}$/', $trimmed) === 1) {
                return $rule + ['id' => $trimmed];
            }
        }

        return null;
    }

    public static function prefix(?string $id): ?string
    {
        return self::parse($id)['prefix'] ?? null;
    }

    public static function owner(?string $id): ?string
    {
        return self::parse($id)['owner'] ?? null;
    }

    public static function isHardwareByPrefix(?string $id): bool
    {
        return (bool) (self::parse($id)['hardware'] ?? false);
    }
}
