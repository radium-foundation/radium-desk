<?php

namespace App\Support\Finance;

/**
 * Official GST state/UT codes used only to resolve a B2B customer's
 * registered state. This is not a seller-GSTIN table and does not
 * invent legal identity.
 */
final class GstStateCodes
{
    public const DELHI = '07';

    public const MAHARASHTRA = '27';

    /**
     * @return array<string, string> GST code => IndianStates name
     */
    public static function namesByCode(): array
    {
        return [
            '01' => 'Jammu and Kashmir',
            '02' => 'Himachal Pradesh',
            '03' => 'Punjab',
            '04' => 'Chandigarh',
            '05' => 'Uttarakhand',
            '06' => 'Haryana',
            '07' => 'Delhi',
            '08' => 'Rajasthan',
            '09' => 'Uttar Pradesh',
            '10' => 'Bihar',
            '11' => 'Sikkim',
            '12' => 'Arunachal Pradesh',
            '13' => 'Nagaland',
            '14' => 'Manipur',
            '15' => 'Mizoram',
            '16' => 'Tripura',
            '17' => 'Meghalaya',
            '18' => 'Assam',
            '19' => 'West Bengal',
            '20' => 'Jharkhand',
            '21' => 'Odisha',
            '22' => 'Chhattisgarh',
            '23' => 'Madhya Pradesh',
            '24' => 'Gujarat',
            '26' => 'Dadra and Nagar Haveli and Daman and Diu',
            '27' => 'Maharashtra',
            '29' => 'Karnataka',
            '30' => 'Goa',
            '31' => 'Lakshadweep',
            '32' => 'Kerala',
            '33' => 'Tamil Nadu',
            '34' => 'Puducherry',
            '35' => 'Andaman and Nicobar Islands',
            '36' => 'Telangana',
            '37' => 'Andhra Pradesh',
            '38' => 'Ladakh',
        ];
    }

    public static function nameForCode(?string $code): ?string
    {
        $code = self::normalizeCode($code);

        return $code === null ? null : (self::namesByCode()[$code] ?? null);
    }

    public static function codeForName(?string $name): ?string
    {
        $name = is_string($name) ? trim($name) : '';
        if ($name === '' || ! IndianStates::contains($name)) {
            return null;
        }

        foreach (self::namesByCode() as $code => $mapped) {
            if ($mapped === $name) {
                return $code;
            }
        }

        return null;
    }

    public static function isKnownCode(?string $code): bool
    {
        $code = self::normalizeCode($code);

        return $code !== null && isset(self::namesByCode()[$code]);
    }

    public static function isMaharashtraCode(?string $code): bool
    {
        return self::normalizeCode($code) === self::MAHARASHTRA;
    }

    public static function isMaharashtraName(?string $name): bool
    {
        return is_string($name) && trim($name) === 'Maharashtra';
    }

    private static function normalizeCode(?string $code): ?string
    {
        if (! is_string($code)) {
            return null;
        }

        $code = trim($code);
        if (preg_match('/^\d{2}$/', $code) !== 1) {
            return null;
        }

        return $code;
    }
}
