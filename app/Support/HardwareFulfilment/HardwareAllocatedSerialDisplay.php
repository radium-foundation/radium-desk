<?php

namespace App\Support\HardwareFulfilment;

/**
 * Compact allocated-serial presentation. Does not allocate or mutate serials.
 */
final class HardwareAllocatedSerialDisplay
{
    /**
     * @param  list<string>|array<int, mixed>  $serials
     * @return list<string>
     */
    public static function normalize(array $serials): array
    {
        $out = [];
        foreach ($serials as $serial) {
            $value = trim((string) $serial);
            if ($value !== '') {
                $out[] = $value;
            }
        }

        return array_values($out);
    }

    /**
     * @param  list<string>|array<int, mixed>  $serials
     */
    public static function compact(array $serials, ?int $expected = null): string
    {
        $serials = self::normalize($serials);
        if ($serials === []) {
            return '—';
        }

        $count = count($serials);
        if ($expected !== null && $expected > 0 && $count !== $expected) {
            return sprintf('Serials: %d / %d allocated', $count, $expected);
        }

        if ($count === 1) {
            return $serials[0];
        }

        return $serials[0].' +'.($count - 1);
    }

    /**
     * @param  list<string>|array<int, mixed>  $serials
     */
    public static function isComplete(array $serials, ?int $expected = null): bool
    {
        $serials = self::normalize($serials);
        if ($expected === null) {
            return $serials !== [];
        }

        return $expected > 0 && count($serials) === $expected;
    }

    /**
     * @param  list<string>|array<int, mixed>  $serials
     */
    public static function copyValue(array $serials): string
    {
        return implode("\n", self::normalize($serials));
    }

    /**
     * @param  list<string>|array<int, mixed>  $serials
     */
    public static function copyToast(array $serials): string
    {
        $count = count(self::normalize($serials));
        if ($count === 1) {
            return 'Copied 1 serial';
        }

        return 'Copied '.$count.' serials';
    }
}
