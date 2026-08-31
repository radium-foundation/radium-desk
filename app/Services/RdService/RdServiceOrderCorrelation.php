<?php

namespace App\Services\RdService;

/**
 * Matches a Desk lookup key against an RDService.net payload without collapsing
 * customer_order_id, cashfree_order_id, and stored provider rdorderid.
 */
final class RdServiceOrderCorrelation
{
    /**
     * @param  list<string|null>  $storedProviderIds  correlation.rdorderid, rd_order.rdorderid, rd_order.order_id
     */
    public static function matches(
        string $lookup,
        ?string $customerOrderId,
        ?string $cashfreeOrderId,
        array $storedProviderIds,
        mixed $storedNumericId = null,
    ): bool {
        $lookup = trim($lookup);

        if ($lookup === '') {
            return false;
        }

        $customerOrderId = self::nullableTrim($customerOrderId);
        $cashfreeOrderId = self::nullableTrim($cashfreeOrderId);
        $storedProviderIds = self::distinct(array_map(self::nullableTrim(...), $storedProviderIds));

        $distinctIds = self::distinct([
            $customerOrderId,
            $cashfreeOrderId,
            ...$storedProviderIds,
        ]);

        $lookupNamespace = RdServiceOrderId::namespacePrefix($lookup);

        if ($lookupNamespace === 'RA' && self::hasRdNamespaced($distinctIds)) {
            return false;
        }

        if (self::storedProviderConflicts($lookup, $storedProviderIds)) {
            return false;
        }

        foreach ($distinctIds as $id) {
            if (strcasecmp($lookup, $id) === 0) {
                return true;
            }
        }

        if ($distinctIds === []) {
            return $lookupNamespace !== 'RA';
        }

        if ($lookupNamespace !== 'RA' || RdServiceOrderId::hasTSuffix($lookup)) {
            return false;
        }

        $lookupCore = RdServiceOrderId::numericCore($lookup);

        if ($lookupCore === null || ! self::hasRaNamespaced($distinctIds)) {
            return false;
        }

        $coreMatched = false;

        foreach ($distinctIds as $id) {
            if (! RdServiceOrderId::isRaNamespaced($id)) {
                continue;
            }

            $core = RdServiceOrderId::numericCore($id);

            if ($core === null) {
                continue;
            }

            if ($core !== $lookupCore) {
                return false;
            }

            $coreMatched = true;
        }

        if ($coreMatched) {
            return true;
        }

        return self::numericIdEquals($storedNumericId, $lookupCore);
    }

    /**
     * Identifier the Admin-shaped mapper should exact-match (stored provider id,
     * not the customer-facing RA{n} lookup key when they differ).
     *
     * @param  list<string|null>  $storedProviderIds
     */
    public static function adminExpectedOrderId(
        string $lookup,
        ?string $customerOrderId,
        ?string $cashfreeOrderId,
        array $storedProviderIds,
    ): string {
        foreach ([...$storedProviderIds, $cashfreeOrderId, $customerOrderId] as $id) {
            $trimmed = self::nullableTrim($id);

            if ($trimmed !== null) {
                return $trimmed;
            }
        }

        return trim($lookup);
    }

    /**
     * @param  list<string|null>  $ids
     * @return list<string>
     */
    private static function distinct(array $ids): array
    {
        $seen = [];
        $unique = [];

        foreach ($ids as $id) {
            if ($id === null || $id === '') {
                continue;
            }

            $key = strtoupper($id);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $id;
        }

        return $unique;
    }

    /**
     * @param  list<string>  $storedProviderIds
     */
    private static function storedProviderConflicts(string $lookup, array $storedProviderIds): bool
    {
        $lookupNamespace = RdServiceOrderId::namespacePrefix($lookup);
        $lookupCore = RdServiceOrderId::numericCore($lookup);
        $lookupHasT = RdServiceOrderId::hasTSuffix($lookup);

        foreach ($storedProviderIds as $stored) {
            if (strcasecmp($lookup, $stored) === 0) {
                continue;
            }

            if (
                $lookupNamespace === 'RA'
                && ! $lookupHasT
                && RdServiceOrderId::isRaNamespaced($stored)
            ) {
                $storedCore = RdServiceOrderId::numericCore($stored);

                if ($storedCore === null || $storedCore === $lookupCore) {
                    continue;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * @param  list<string>  $ids
     */
    private static function hasRdNamespaced(array $ids): bool
    {
        foreach ($ids as $id) {
            if (RdServiceOrderId::isRdNamespaced($id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $ids
     */
    private static function hasRaNamespaced(array $ids): bool
    {
        foreach ($ids as $id) {
            if (RdServiceOrderId::isRaNamespaced($id)) {
                return true;
            }
        }

        return false;
    }

    private static function numericIdEquals(mixed $storedNumericId, string $lookupCore): bool
    {
        if (is_int($storedNumericId)) {
            return $storedNumericId > 0 && (string) $storedNumericId === $lookupCore;
        }

        if (! is_string($storedNumericId)) {
            return false;
        }

        $trimmed = trim($storedNumericId);

        return ctype_digit($trimmed) && $trimmed === $lookupCore && (int) $trimmed > 0;
    }

    private static function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
