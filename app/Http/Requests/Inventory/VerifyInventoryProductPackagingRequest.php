<?php

namespace App\Http\Requests\Inventory;

use App\Support\Inventory\InventoryAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VerifyInventoryProductPackagingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return InventoryAccess::allowsPermission(
            $this->user(),
            RolePermissionSeeder::PERMISSION_INVENTORY_PACKAGING_VERIFY,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'gross_weight' => ['required', 'numeric', 'gt:0'],
            'length' => ['required', 'numeric', 'gt:0'],
            'breadth' => ['required', 'numeric', 'gt:0'],
            'height' => ['required', 'numeric', 'gt:0'],
            'weight_unit' => ['required', 'string', Rule::in(['kg'])],
            'dimension_unit' => ['required', 'string', Rule::in(['cm'])],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'gross_weight.gt' => 'Gross packed weight must be greater than zero.',
            'length.gt' => 'Packed length must be greater than zero.',
            'breadth.gt' => 'Packed breadth must be greater than zero.',
            'height.gt' => 'Packed height must be greater than zero.',
            'weight_unit.in' => 'Weight unit must be kg. Other units are not converted.',
            'dimension_unit.in' => 'Dimension unit must be cm. Other units are not converted.',
        ];
    }
}
