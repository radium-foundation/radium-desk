<?php

namespace App\Services\DashboardOemHandoff;

use App\Support\Oem\OemWarrantyCheckVendor;
use Illuminate\Validation\ValidationException;

final class OemHandoffResultValidator
{
    public function __construct(
        private readonly MantraHandoffResultValidator $mantra,
        private readonly MorphoHandoffResultValidator $morpho,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function validateDraft(string $oem, string $expectedSerial, array $payload): array
    {
        return match ($oem) {
            OemWarrantyCheckVendor::Mantra->value => $this->mantra->validateDraft($expectedSerial, $payload),
            OemWarrantyCheckVendor::Morpho->value => $this->morpho->validateDraft($expectedSerial, $payload),
            default => throw ValidationException::withMessages(['oem' => ['Unsupported OEM for result capture.']]),
        };
    }
}