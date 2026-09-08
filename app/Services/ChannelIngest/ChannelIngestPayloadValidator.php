<?php

namespace App\Services\ChannelIngest;

use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Services\ChannelIngest\Data\ChannelOrderIngestRequest;
use App\Services\ChannelIngest\Data\ChannelOrderLineDraft;
use App\Services\HardwareFulfilment\HardwareHandoffTenderContract;
use App\Support\Finance\IndianStates;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class ChannelIngestPayloadValidator
{
    public function __construct(
        private readonly HardwareHandoffTenderContract $tenders = new HardwareHandoffTenderContract,
    ) {}

    /**
     * HTTP ingest channels. Desk POS is in-process only.
     *
     * @return list<string>
     */
    public static function httpChannels(): array
    {
        return [
            StatutoryInvoiceChannel::RdServiceIn->value,
            StatutoryInvoiceChannel::RadiumBoxCom->value,
            StatutoryInvoiceChannel::RdServiceNet->value,
            StatutoryInvoiceChannel::RadiumSignCom->value,
            StatutoryInvoiceChannel::Future->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function validate(array $payload, StatutoryInvoiceChannel $authenticatedChannel): ChannelOrderIngestRequest
    {
        $validator = validator($payload, $this->rules());
        $this->after($validator, $authenticatedChannel);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        /** @var array<string, mixed> $data */
        $data = $validator->validated();

        $lines = [];
        foreach ($data['lines'] as $line) {
            $lines[] = new ChannelOrderLineDraft(
                description: (string) $line['description'],
                qty: (int) $line['qty'],
                unitPrice: (float) $line['unit_price'],
                sku: $this->nullableString($line['sku'] ?? null),
                variant: $this->nullableString($line['variant'] ?? null),
                hsnSac: $this->nullableString($line['hsn_sac'] ?? null),
                discount: $this->nullableFloat($line['discount'] ?? null),
                gstPercentage: $this->nullableFloat($line['gst_percentage'] ?? null),
                taxableValue: $this->nullableFloat($line['taxable_value'] ?? null),
                taxTotal: $this->nullableFloat($line['tax_total'] ?? null),
                cgst: $this->nullableFloat($line['cgst'] ?? null),
                sgst: $this->nullableFloat($line['sgst'] ?? null),
                igst: $this->nullableFloat($line['igst'] ?? null),
                lineTotal: $this->nullableFloat($line['line_total'] ?? null),
                shippingLineKind: $this->nullableString($line['shipping_line_kind'] ?? null),
                requiresShipping: $this->nullableBool($line['requires_shipping'] ?? null),
                productId: $this->nullableInt($line['product_id'] ?? null),
                modelId: $this->nullableInt($line['model_id'] ?? null),
                catalogSku: $this->nullableString($line['catalog_sku'] ?? null),
                rdserviceid: $this->nullableInt($line['rdserviceid'] ?? null),
                amcid: $this->nullableInt($line['amcid'] ?? null),
                otgid: $this->nullableInt($line['otgid'] ?? null),
            );
        }

        $customer = is_array($data['customer'] ?? null) ? $data['customer'] : [];
        $rawCustomer = is_array($payload['customer'] ?? null) ? $payload['customer'] : [];
        $rawBilling = $payload['billing_address'] ?? $rawCustomer['billing_address'] ?? null;
        $rawShipping = $payload['shipping_address'] ?? $rawCustomer['shipping_address'] ?? null;

        $request = new ChannelOrderIngestRequest(
            channel: StatutoryInvoiceChannel::from((string) $data['channel']),
            sourceType: StatutoryInvoiceSourceType::from((string) $data['source_type']),
            sourceId: (string) $data['source_id'],
            lines: $lines,
            paymentStatus: (string) $data['payment_status'],
            currency: strtoupper((string) $data['currency']),
            sourceOrderId: $this->nullableString($data['source_order_id'] ?? $data['external_order_id'] ?? null),
            paymentProvider: $this->nullableString($data['payment_provider'] ?? null),
            paymentReference: $this->nullableString($data['payment_reference'] ?? null),
            paymentMethod: $this->nullableString($data['payment_method'] ?? $data['payment_mode'] ?? null),
            customerName: $this->nullableString($customer['name'] ?? null),
            customerPhone: $this->nullableString($customer['phone'] ?? null),
            customerEmail: $this->nullableString($customer['email'] ?? null),
            buyerGstin: $this->nullableString($customer['gstin'] ?? $data['buyer_gstin'] ?? null),
            billingAddress: $this->address($rawBilling),
            billingState: $this->structuredBillingState($rawBilling),
            shippingAddress: $this->address($rawShipping),
            sellerGstin: $this->nullableString($data['seller_gstin'] ?? null),
            sellerName: $this->nullableString($data['seller_name'] ?? null),
            branchCode: $this->nullableString($data['branch_code'] ?? null),
            placeOfSupplyState: $this->nullableString($data['place_of_supply_state'] ?? null),
            discount: $this->nullableFloat($data['discount'] ?? null),
            metadata: $this->redactMetadata(is_array($data['metadata'] ?? null) ? $data['metadata'] : []),
            orderedAt: $this->nullableString($data['ordered_at'] ?? null),
            paidAt: $this->nullableString($data['paid_at'] ?? null),
            supportOrderId: isset($data['support_order_id']) ? (int) $data['support_order_id'] : null,
            billingAddressStructured: $this->structuredAddress($rawBilling),
            shippingAddressStructured: $this->structuredAddress($rawShipping),
            parcel: $this->structuredParcel($data['parcel'] ?? $payload['parcel'] ?? null),
            tenders: $this->tenders->parse($data['tenders'] ?? $payload['tenders'] ?? null),
        );

        $this->tenders->assertPayload($request);

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'channel' => ['required', 'string', 'in:'.implode(',', self::httpChannels())],
            'source_type' => ['required', 'string', 'in:commerce_order,support_order,external'],
            'source_id' => ['required', 'string', 'max:80'],
            'source_order_id' => ['nullable', 'string', 'max:80'],
            'external_order_id' => ['nullable', 'string', 'max:80'],
            'payment_status' => ['required', 'string', 'in:paid,pending,failed,refunded'],
            'payment_provider' => ['nullable', 'string', 'max:64'],
            'payment_reference' => ['nullable', 'string', 'max:128'],
            'payment_method' => ['nullable', 'string', 'max:64'],
            'payment_mode' => ['nullable', 'string', 'max:64'],
            'currency' => ['required', 'string', 'size:3', 'in:INR'],
            'customer' => ['required', 'array'],
            'customer.name' => ['nullable', 'string', 'max:255'],
            'customer.phone' => ['nullable', 'string', 'max:20'],
            'customer.email' => ['nullable', 'email', 'max:255'],
            'customer.gstin' => ['nullable', 'string', 'max:32'],
            'buyer_gstin' => ['nullable', 'string', 'max:32'],
            'billing_address' => ['nullable'],
            'billing_address.state' => ['nullable', 'string', 'max:64'],
            'customer.billing_address' => ['nullable'],
            'customer.billing_address.state' => ['nullable', 'string', 'max:64'],
            'shipping_address' => ['nullable'],
            'seller_gstin' => ['nullable', 'string', 'max:32'],
            'seller_name' => ['nullable', 'string', 'max:255'],
            'branch_code' => ['nullable', 'string', 'max:32'],
            'place_of_supply_state' => ['nullable', 'string', 'max:64'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'ordered_at' => ['nullable', 'date'],
            'paid_at' => ['nullable', 'date'],
            'support_order_id' => ['nullable', 'integer', 'min:1'],
            'metadata' => ['nullable', 'array'],
            'parcel' => ['nullable', 'array'],
            'parcel.weight' => ['nullable', 'numeric', 'min:0'],
            'parcel.length' => ['nullable', 'numeric', 'min:0'],
            'parcel.breadth' => ['nullable', 'numeric', 'min:0'],
            'parcel.width' => ['nullable', 'numeric', 'min:0'],
            'parcel.height' => ['nullable', 'numeric', 'min:0'],
            'parcel.weight_unit' => ['nullable', 'string', 'max:16'],
            'tenders' => ['nullable', 'array'],
            'tenders.*.type' => ['required_with:tenders', 'string', 'in:cashfree,wallet'],
            'tenders.*.amount' => ['required_with:tenders', 'numeric', 'min:0.01'],
            'tenders.*.reference' => ['nullable', 'string', 'max:128'],
            'shipping_address.line1' => ['nullable', 'string', 'max:255'],
            'shipping_address.line2' => ['nullable', 'string', 'max:255'],
            'shipping_address.city' => ['nullable', 'string', 'max:128'],
            'shipping_address.state' => ['nullable', 'string', 'max:64'],
            'shipping_address.pincode' => ['nullable', 'string', 'max:16'],
            'shipping_address.country' => ['nullable', 'string', 'max:64'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.sku' => ['nullable', 'string', 'max:64'],
            'lines.*.variant' => ['nullable', 'string', 'max:64'],
            'lines.*.hsn_sac' => ['nullable', 'string', 'max:16'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.gst_percentage' => ['nullable', 'numeric', 'min:0'],
            'lines.*.taxable_value' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax_total' => ['nullable', 'numeric', 'min:0'],
            'lines.*.cgst' => ['nullable', 'numeric', 'min:0'],
            'lines.*.sgst' => ['nullable', 'numeric', 'min:0'],
            'lines.*.igst' => ['nullable', 'numeric', 'min:0'],
            'lines.*.line_total' => ['nullable', 'numeric', 'min:0'],
            'lines.*.shipping_line_kind' => ['nullable', 'string', 'max:40'],
            'lines.*.requires_shipping' => ['nullable', 'boolean'],
            'lines.*.product_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.model_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.catalog_sku' => ['nullable', 'string', 'max:64'],
            'lines.*.rdserviceid' => ['nullable', 'integer', 'min:1'],
            'lines.*.amcid' => ['nullable', 'integer', 'min:1'],
            'lines.*.otgid' => ['nullable', 'integer', 'min:1'],
        ];
    }

    private function after(Validator $validator, StatutoryInvoiceChannel $authenticatedChannel): void
    {
        $validator->after(function (Validator $validator) use ($authenticatedChannel): void {
            $channel = (string) $validator->getValue('channel');
            if ($channel !== '' && $channel !== $authenticatedChannel->value) {
                $validator->errors()->add('channel', 'Payload channel must match the authenticated channel.');
            }

            $name = trim((string) Arr::get($validator->getData(), 'customer.name', ''));
            $phone = trim((string) Arr::get($validator->getData(), 'customer.phone', ''));
            if ($name === '' && $phone === '') {
                $validator->errors()->add('customer', 'Customer name or phone is required. Values are not invented.');
            }

            $rawBilling = $validator->getValue('billing_address')
                ?? Arr::get($validator->getData(), 'customer.billing_address');
            $billingState = $this->structuredBillingState($rawBilling);
            if ($billingState !== null && ! IndianStates::contains($billingState)) {
                $validator->errors()->add(
                    'billing_address.state',
                    'Billing state must be a recognised Indian state or union territory. Values are not invented from address text.',
                );
            }
        });
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function nullableBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if ($value === 1 || $value === '1' || $value === 'true') {
            return true;
        }

        if ($value === 0 || $value === '0' || $value === 'false') {
            return false;
        }

        return null;
    }

    /**
     * @return array<string, string>|null
     */
    private function structuredAddress(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $out = [];
        foreach (['line1', 'line2', 'city', 'state', 'pincode', 'country'] as $key) {
            $part = $this->nullableString($value[$key] ?? null);
            if ($part !== null) {
                $out[$key] = $part;
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function structuredParcel(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $out = [];
        foreach (['weight', 'length', 'breadth', 'width', 'height'] as $key) {
            if (! array_key_exists($key, $value) || $value[$key] === null || $value[$key] === '') {
                continue;
            }
            if (! is_numeric($value[$key])) {
                continue;
            }
            $out[$key] = $value[$key] + 0;
        }
        $unit = $this->nullableString($value['weight_unit'] ?? null);
        if ($unit !== null) {
            $out['weight_unit'] = $unit;
        }

        return $out === [] ? null : $out;
    }

    /**
     * Structured checkout state only. Flattened billing_address strings
     * and place_of_supply_state are never parsed or copied here.
     */
    private function structuredBillingState(mixed $value): ?string
    {
        if (! is_array($value)) {
            return null;
        }

        return $this->nullableString($value['state'] ?? null);
    }

    private function address(mixed $value): ?string
    {
        if (is_string($value)) {
            return $this->nullableString($value);
        }

        if (! is_array($value)) {
            return null;
        }

        $parts = [];
        foreach (['line1', 'line2', 'city', 'state', 'pincode', 'country'] as $key) {
            $part = $this->nullableString($value[$key] ?? null);
            if ($part !== null) {
                $parts[] = $part;
            }
        }

        if ($parts === []) {
            return $this->nullableString(implode(', ', array_filter(array_map(
                fn ($item) => is_scalar($item) ? (string) $item : '',
                $value,
            ))));
        }

        return implode(', ', $parts);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function redactMetadata(array $metadata): array
    {
        $clean = [];
        foreach ($metadata as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            if (preg_match('/password|secret|token|authorization|api[_-]?key/i', $key) === 1) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
