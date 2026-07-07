<?php

namespace App\Http\Requests;

use App\Enums\NewContactIntent;
use App\Services\SettingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerIntakeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->can('orders.view')
            && $user->can('incidents.create');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $settingService = app(SettingService::class);

        return [
            'action' => ['required', 'string', Rule::in(['existing_order', 'legacy_radiumbox', 'legacy_import', 'new_contact'])],
            'matched_order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'legacy_order_id' => ['nullable', 'string', 'max:50'],
            'open_only' => ['sometimes', 'boolean'],
            'intent' => ['nullable', 'string', Rule::enum(NewContactIntent::class)],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'product' => ['nullable', 'string', Rule::in($settingService->enabledProductNames())],
            'source' => ['required', 'string', Rule::in($settingService->enabledSourceKeys())],
            'notes' => ['required', 'string', 'max:5000'],
            'high_priority' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'notes' => 'comment / issue description',
            'customer_name' => 'customer name',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $action = $this->string('action')->toString();

            if ($action === 'existing_order' && ! $this->filled('matched_order_id')) {
                $validator->errors()->add('matched_order_id', 'Select an existing order to continue.');
            }

            if ($action === 'legacy_radiumbox' && ! $this->filled('legacy_order_id')) {
                $validator->errors()->add('legacy_order_id', 'Legacy order ID is required.');
            }

            if ($action === 'legacy_import' && ! $this->filled('legacy_order_id')) {
                $validator->errors()->add('legacy_order_id', 'Legacy order ID is required.');
            }

            if ($action === 'new_contact') {
                if (! $this->filled('customer_name')) {
                    $validator->errors()->add('customer_name', 'Customer name is required.');
                }

                if (! $this->filled('intent')) {
                    $validator->errors()->add('intent', 'Select the customer intent before continuing.');
                }

                $intent = NewContactIntent::tryFrom($this->string('intent')->toString());

                if ($intent?->requiresSerial() && ! $this->filled('serial_number')) {
                    $validator->errors()->add('serial_number', 'Serial number is required for existing device service.');
                }

                if ($intent?->requiresProduct() && ! $this->filled('product')) {
                    $validator->errors()->add('product', 'Product is required for existing device service.');
                }
            }
        });
    }
}
