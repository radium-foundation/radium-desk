<?php

namespace App\Services\Interakt;

class InteraktPhoneResolver
{
    /**
     * @return array{country_code: string, phone_number: string}|null
     */
    public function resolveForStoredPhone(?string $customerPhone): ?array
    {
        if (! filled($customerPhone)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $customerPhone) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '91') && strlen($digits) > 10) {
            $local = substr($digits, -10);

            return [
                'country_code' => '+91',
                'phone_number' => $local,
            ];
        }

        if (strlen($digits) === 10) {
            return [
                'country_code' => '+91',
                'phone_number' => $digits,
            ];
        }

        return [
            'country_code' => '+91',
            'phone_number' => $digits,
        ];
    }
}
