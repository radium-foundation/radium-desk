<?php

namespace App\Services\CustomerOrderHistory;

use App\Data\CustomerOrderHistory\CustomerOrderHistoryIdentityResolution;
use App\Models\Order;
use App\Services\Interakt\InteraktCustomerMatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CustomerOrderHistoryIdentityResolver
{
    public function __construct(
        private readonly InteraktCustomerMatcher $customerMatcher,
    ) {}

    public function resolve(
        ?string $phone = null,
        ?string $email = null,
        ?string $businessOrderId = null,
        ?string $serialNumber = null,
    ): CustomerOrderHistoryIdentityResolution {
        $seedOrders = $this->seedOrders($phone, $email, $businessOrderId, $serialNumber);

        if ($seedOrders->isEmpty()) {
            return CustomerOrderHistoryIdentityResolution::unresolved();
        }

        $customerIds = $this->distinctCustomerIds($seedOrders);
        $phones = $this->distinctPhones($seedOrders, $phone);
        $emails = $this->distinctEmails($seedOrders, $email);

        if (count($customerIds) > 1) {
            return CustomerOrderHistoryIdentityResolution::ambiguous(
                'Multiple distinct Cashfree customer identities match this search.',
            );
        }

        if (count($emails) > 1) {
            return CustomerOrderHistoryIdentityResolution::ambiguous(
                'Multiple distinct customer email identities match this search.',
            );
        }

        if ($phones === [] && $emails === [] && $customerIds === []) {
            return CustomerOrderHistoryIdentityResolution::unresolved();
        }

        return new CustomerOrderHistoryIdentityResolution(
            resolved: true,
            ambiguous: false,
            customerIds: $customerIds,
            phones: $phones,
            emails: $emails,
        );
    }

    /**
     * @return Collection<int, Order>
     */
    private function seedOrders(
        ?string $phone,
        ?string $email,
        ?string $businessOrderId,
        ?string $serialNumber,
    ): Collection {
        $orders = collect();

        $businessOrderId = $this->nullableTrim($businessOrderId);
        if ($businessOrderId !== null) {
            $byOrderId = Order::query()->where('order_id', $businessOrderId)->first();
            if ($byOrderId !== null) {
                $orders->push($byOrderId);
            }
        }

        $serialNumber = $this->normalizeSerial($serialNumber);
        if ($serialNumber !== null) {
            $orders = $orders->merge(
                Order::query()->where('serial_number', $serialNumber)->get(),
            );
        }

        $phone = $this->nullableTrim($phone);
        if ($phone !== null) {
            $storedPhones = $this->customerMatcher->matchingStoredPhones(null, $phone);
            if ($storedPhones !== []) {
                $orders = $orders->merge(
                    Order::query()->whereIn('customer_phone', $storedPhones)->get(),
                );
            }
        }

        $email = $this->normalizeEmail($email);
        if ($email !== null) {
            $orders = $orders->merge(
                Order::query()
                    ->whereRaw('LOWER(TRIM(customer_email)) = ?', [$email])
                    ->get(),
            );
        }

        return $orders->unique('id')->values();
    }

    /**
     * @param  Collection<int, Order>  $seedOrders
     * @return list<string>
     */
    private function distinctCustomerIds(Collection $seedOrders): array
    {
        return $seedOrders
            ->map(fn (Order $order): string => trim((string) $order->customer_id))
            ->filter(fn (string $id): bool => $id !== '' && Str::isUuid($id))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Order>  $seedOrders
     * @return list<string>
     */
    private function distinctPhones(Collection $seedOrders, ?string $searchPhone): array
    {
        $phones = collect();

        foreach ($seedOrders as $order) {
            $stored = $this->customerMatcher->matchingStoredPhones(null, (string) $order->customer_phone);
            $phones = $phones->merge($stored);
        }

        $searchPhone = $this->nullableTrim($searchPhone);
        if ($searchPhone !== null) {
            $phones = $phones->merge($this->customerMatcher->matchingStoredPhones(null, $searchPhone));
        }

        return $phones->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Order>  $seedOrders
     * @return list<string>
     */
    private function distinctEmails(Collection $seedOrders, ?string $searchEmail): array
    {
        $emails = $seedOrders
            ->map(fn (Order $order): ?string => $this->normalizeEmail($order->customer_email))
            ->filter()
            ->unique()
            ->values();

        $searchEmail = $this->normalizeEmail($searchEmail);
        if ($searchEmail !== null) {
            $emails->push($searchEmail);
        }

        return $emails->unique()->values()->all();
    }

    private function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeEmail(?string $email): ?string
    {
        $email = $this->nullableTrim($email);

        if ($email === null || ! str_contains($email, '@')) {
            return null;
        }

        return strtolower($email);
    }

    private function normalizeSerial(?string $serial): ?string
    {
        if ($serial === null) {
            return null;
        }

        $normalized = strtoupper(trim($serial));

        return $normalized === '' ? null : $normalized;
    }
}
