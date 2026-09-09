<?php

namespace App\Services\Shipping\Data;

use App\Services\Shipping\ShiprocketAdhocAddressLimitException;
use Illuminate\Validation\ValidationException;

final class ShiprocketCreateOrderRequest
{
    /**
     * Verified POST /orders/create/adhoc fields only.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public function __construct(
        public readonly string $merchantOrderId,
        public readonly int $localShipmentId,
        public readonly string $correlationId,
        public readonly array $items = [],
        public readonly string $orderDate = '',
        public readonly string $pickupLocation = '',
        public readonly string $billingCustomerName = '',
        public readonly string $billingAddress = '',
        public readonly string $billingCity = '',
        public readonly string $billingPincode = '',
        public readonly string $billingState = '',
        public readonly string $billingCountry = '',
        public readonly string $billingEmail = '',
        public readonly string $billingPhone = '',
        public readonly bool $shippingIsBilling = true,
        public readonly string $paymentMethod = '',
        public readonly string $subTotal = '',
        public readonly float $length = 0.0,
        public readonly float $breadth = 0.0,
        public readonly float $height = 0.0,
        public readonly float $weight = 0.0,
        public readonly ?string $billingLastName = null,
        public readonly ?string $billingAddress2 = null,
        public readonly ?string $channelId = null,
        public readonly ?string $customerGstin = null,
        public readonly ?string $shippingCustomerName = null,
        public readonly ?string $shippingAddress = null,
        public readonly ?string $shippingAddress2 = null,
        public readonly ?string $shippingCity = null,
        public readonly ?string $shippingPincode = null,
        public readonly ?string $shippingState = null,
        public readonly ?string $shippingCountry = null,
        public readonly ?string $shippingPhone = null,
    ) {}

    /**
     * Official create/adhoc JSON. Optional keys are omitted when empty.
     *
     * @return array<string, mixed>
     */
    public function toAdhocPayload(): array
    {
        [$billingAddress, $billingAddress2] = $this->adhocAddressLines(
            $this->billingAddress,
            $this->billingAddress2,
            $this->billingState,
            $this->billingPincode,
        );

        $payload = [
            'order_id' => $this->merchantOrderId,
            'order_date' => $this->orderDate,
            'pickup_location' => $this->pickupLocation,
            'billing_customer_name' => $this->billingCustomerName,
            'billing_address' => $billingAddress,
            'billing_city' => $this->billingCityForAdhoc(),
            'billing_pincode' => $this->billingPincode,
            'billing_state' => $this->billingState,
            'billing_country' => $this->billingCountry,
            'billing_email' => $this->billingEmail,
            'billing_phone' => $this->billingPhone,
            'billing_last_name' => $this->billingLastName !== null ? $this->billingLastName : '',
            'shipping_is_billing' => $this->shippingIsBilling,
            'order_items' => $this->items,
            'payment_method' => $this->paymentMethod,
            'sub_total' => $this->subTotalAsNumber(),
            'length' => $this->length,
            'breadth' => $this->breadth,
            'height' => $this->height,
            'weight' => $this->weight,
        ];

        if ($billingAddress2 !== null && trim($billingAddress2) !== '') {
            $payload['billing_address_2'] = $billingAddress2;
        }
        if ($this->channelId !== null && trim($this->channelId) !== '') {
            $payload['channel_id'] = $this->channelId;
        }
        if ($this->customerGstin !== null && trim($this->customerGstin) !== '') {
            $payload['customer_gstin'] = $this->customerGstin;
        }

        if (! $this->shippingIsBilling) {
            [$shippingAddress, $shippingAddress2] = $this->adhocAddressLines(
                (string) $this->shippingAddress,
                $this->shippingAddress2,
                (string) $this->shippingState,
                (string) $this->shippingPincode,
            );

            $payload['shipping_customer_name'] = $this->shippingCustomerName;
            $payload['shipping_address'] = $this->shippingAddress === null && $shippingAddress === ''
                ? $this->shippingAddress
                : $shippingAddress;
            $payload['shipping_city'] = $this->shippingCity;
            $payload['shipping_pincode'] = $this->shippingPincode;
            $payload['shipping_state'] = $this->shippingState;
            $payload['shipping_country'] = $this->shippingCountry;
            $payload['shipping_phone'] = $this->shippingPhone;
            if ($shippingAddress2 !== null && trim($shippingAddress2) !== '') {
                $payload['shipping_address_2'] = $shippingAddress2;
            }
        }

        return $payload;
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function adhocAddressLines(string $line1, ?string $line2, string $state, string $pincode): array
    {
        try {
            return ShiprocketAdhocAddressNormalizer::forPayload($line1, $line2, $state, $pincode);
        } catch (ShiprocketAdhocAddressLimitException $exception) {
            throw ValidationException::withMessages([
                'shipping' => $exception->getMessage(),
            ]);
        }
    }

    public function subTotalAsNumber(): float
    {
        return round((float) $this->subTotal, 2);
    }

    /**
     * Official create/adhoc documents billing_city as required, max 30 characters.
     * Does not invent a city. Trailing " (alias)" is dropped only when the
     * remaining stored name already fits. Otherwise the stored value is cut to 30.
     */
    public function billingCityForAdhoc(): string
    {
        $city = trim($this->billingCity);
        if ($this->characterLength($city) <= 30) {
            return $city;
        }

        if (preg_match('/^(.*)\s+\([^)]+\)\s*$/u', $city, $matches) === 1) {
            $withoutAlias = trim((string) $matches[1]);
            if ($withoutAlias !== '' && $this->characterLength($withoutAlias) <= 30) {
                return $withoutAlias;
            }
        }

        return rtrim($this->limitCharacters($city, 30));
    }

    private function characterLength(string $value): int
    {
        return mb_strlen($value, 'UTF-8');
    }

    private function limitCharacters(string $value, int $max): string
    {
        return mb_substr($value, 0, $max, 'UTF-8');
    }
}
