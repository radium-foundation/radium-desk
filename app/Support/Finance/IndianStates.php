<?php

namespace App\Support\Finance;

/**
 * Official Indian states and union territories for place-of-supply selection.
 *
 * This is a UI/validation list only. It is not a GST split table and does not
 * derive seller GSTIN, series, or CGST/SGST/IGST.
 */
final class IndianStates
{
    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return [
            'Andaman and Nicobar Islands',
            'Andhra Pradesh',
            'Arunachal Pradesh',
            'Assam',
            'Bihar',
            'Chandigarh',
            'Chhattisgarh',
            'Dadra and Nagar Haveli and Daman and Diu',
            'Delhi',
            'Goa',
            'Gujarat',
            'Haryana',
            'Himachal Pradesh',
            'Jammu and Kashmir',
            'Jharkhand',
            'Karnataka',
            'Kerala',
            'Ladakh',
            'Lakshadweep',
            'Madhya Pradesh',
            'Maharashtra',
            'Manipur',
            'Meghalaya',
            'Mizoram',
            'Nagaland',
            'Odisha',
            'Puducherry',
            'Punjab',
            'Rajasthan',
            'Sikkim',
            'Tamil Nadu',
            'Telangana',
            'Tripura',
            'Uttar Pradesh',
            'Uttarakhand',
            'West Bengal',
        ];
    }

    public static function contains(?string $name): bool
    {
        if ($name === null) {
            return false;
        }

        $name = trim($name);

        return $name !== '' && in_array($name, self::names(), true);
    }
}
