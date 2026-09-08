<?php

namespace App\Http\Requests\Inventory;

use App\Enums\HardwareFulfilmentPackageEvidenceKind;
use App\Support\HardwareFulfilment\HardwareFulfilmentAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHardwareFulfilmentPackageEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return HardwareFulfilmentAccess::allows($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', Rule::enum(HardwareFulfilmentPackageEvidenceKind::class)],
            'photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'channel_id' => ['prohibited'],
            'path' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'kind.required' => 'Choose the package photo type.',
            'photo.required' => 'Attach a package photo.',
        ];
    }
}
