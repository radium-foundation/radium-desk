<?php

namespace App\Services\Interakt;

use App\Models\Order;
use Illuminate\Support\Collection;

class InteraktCustomerMatcher
{
    /**
     * @return list<string>
     */
    public function phoneCandidates(?string $countryCode, ?string $phoneNumber): array
    {
        if (! filled($phoneNumber)) {
            return [];
        }

        $phoneDigits = preg_replace('/\D+/', '', (string) $phoneNumber) ?? '';
        $countryDigits = preg_replace('/\D+/', '', (string) ($countryCode ?? '')) ?? '';

        $candidates = [
            trim((string) $phoneNumber),
            $phoneDigits,
        ];

        if ($countryDigits !== '' && $phoneDigits !== '') {
            $candidates[] = '+'.$countryDigits.$phoneDigits;
            $candidates[] = $countryDigits.$phoneDigits;
        }

        if (strlen($phoneDigits) > 10) {
            $candidates[] = substr($phoneDigits, -10);
        }

        return array_values(array_unique(array_filter($candidates, fn (string $value): bool => $value !== '')));
    }

    /**
     * @return list<string>
     */
    public function channelPhoneCandidates(?string $channelPhoneNumber): array
    {
        if (! filled($channelPhoneNumber)) {
            return [];
        }

        $digits = preg_replace('/\D+/', '', (string) $channelPhoneNumber) ?? '';

        if ($digits === '') {
            return [];
        }

        $candidates = [
            trim((string) $channelPhoneNumber),
            $digits,
            '+'.$digits,
        ];

        if (strlen($digits) > 10) {
            $candidates[] = substr($digits, -10);
        }

        if (str_starts_with($digits, '91') && strlen($digits) >= 12) {
            $local = substr($digits, 2);
            $candidates[] = $local;
            $candidates[] = '+'.$local;
            $candidates[] = '+91'.$local;
        }

        return array_values(array_unique(array_filter($candidates, fn (string $value): bool => $value !== '')));
    }

    /**
     * @return list<string>
     */
    public function matchingStoredPhones(?string $countryCode, ?string $phoneNumber, ?string $channelPhoneNumber = null): array
    {
        $candidates = array_values(array_unique(array_merge(
            $this->channelPhoneCandidates($channelPhoneNumber),
            $this->phoneCandidates($countryCode, $phoneNumber),
        )));

        if ($candidates === []) {
            return [];
        }

        return Order::query()
            ->whereIn('customer_phone', $candidates)
            ->whereNotNull('customer_phone')
            ->pluck('customer_phone')
            ->unique()
            ->values()
            ->all();
    }

    public function resolveStoredPhone(
        ?string $countryCode = null,
        ?string $phoneNumber = null,
        ?string $channelPhoneNumber = null,
    ): ?string {
        $matches = $this->matchingStoredPhones($countryCode, $phoneNumber, $channelPhoneNumber);

        if ($matches !== []) {
            return $matches[0];
        }

        return $this->channelPhoneCandidates($channelPhoneNumber)[0]
            ?? $this->phoneCandidates($countryCode, $phoneNumber)[0]
            ?? null;
    }

    /**
     * @return Collection<int, Order>
     */
    public function ordersForPhone(?string $customerPhone): Collection
    {
        if (! filled($customerPhone)) {
            return collect();
        }

        return Order::query()
            ->where('customer_phone', $customerPhone)
            ->orderByDesc('id')
            ->get();
    }
}
