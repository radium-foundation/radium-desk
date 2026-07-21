<?php

namespace App\Services\DeviceIdentityCorrection;

use App\Data\SerialValidationResult;
use App\Enums\SerialValidationSeverity;
use App\Models\DeviceModel;
use App\Models\Order;
use App\Services\DeviceModelAliasResolver;
use App\Services\SerialValidation\SerialModelPatternProfileService;
use App\Services\SerialValidation\SerialValidationService;

class DeviceIdentitySerialModelResolver
{
    public function __construct(
        private readonly SerialValidationService $serialValidationService,
        private readonly SerialModelPatternProfileService $patternProfileService,
        private readonly DeviceModelAliasResolver $deviceModelAliasResolver,
    ) {}

    /**
     * @return array{
     *     normalized_serial: string,
     *     severity: string|null,
     *     status: string|null,
     *     reason: string|null,
     *     corrected: bool,
     *     duplicate: bool,
     *     duplicate_order_id: string|null,
     *     allows_workflow: bool,
     *     detection: array{
     *         detected_product: string|null,
     *         detected_device_model_id: int|null,
     *         detected_device_model_name: string|null,
     *         selected_product: string|null,
     *         cross_model_hint: string|null,
     *         matches_selected: bool,
     *     },
     *     outcome: 'match'|'mismatch'|'undetermined'|'validation_failed',
     * }
     */
    public function preview(DeviceModel $selectedModel, string $serial, ?Order $order = null): array
    {
        $normalized = strtoupper(trim($serial));

        if ($normalized === '') {
            return $this->emptyPreview('Enter a serial number to validate.');
        }

        $selectedProduct = $this->serialValidationService->resolveProductName($selectedModel->name);
        $validation = $this->serialValidationService->validate($normalized, $selectedModel->name);
        $assessment = $this->patternProfileService->assess($selectedModel->name, $normalized, $validation);
        $detectedProduct = $this->detectProduct($normalized);
        $detectedModel = $this->resolveDetectedModel($detectedProduct, $assessment->crossModelHint);
        $duplicate = $this->duplicateOwner($order, $validation->normalizedSerial);

        $outcome = $this->resolveOutcome(
            $validation,
            $selectedProduct,
            $detectedProduct,
            $assessment->crossModelHint,
        );

        return [
            'normalized_serial' => $validation->normalizedSerial,
            'severity' => $validation->severity->value,
            'status' => $validation->status->value,
            'reason' => $validation->reason,
            'corrected' => $validation->corrected,
            'duplicate' => $duplicate !== null,
            'duplicate_order_id' => $duplicate?->order_id,
            'allows_workflow' => $validation->allowsWorkflow() && $duplicate === null,
            'detection' => [
                'detected_product' => $detectedProduct,
                'detected_device_model_id' => $detectedModel?->id,
                'detected_device_model_name' => $detectedModel?->name ?? $assessment->crossModelHint,
                'selected_product' => $selectedProduct,
                'cross_model_hint' => $assessment->crossModelHint,
                'matches_selected' => $outcome === 'match',
            ],
            'outcome' => $outcome,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPreview(string $message): array
    {
        return [
            'normalized_serial' => '',
            'severity' => null,
            'status' => null,
            'reason' => $message,
            'corrected' => false,
            'duplicate' => false,
            'duplicate_order_id' => null,
            'allows_workflow' => false,
            'detection' => [
                'detected_product' => null,
                'detected_device_model_id' => null,
                'detected_device_model_name' => null,
                'selected_product' => null,
                'cross_model_hint' => null,
                'matches_selected' => false,
            ],
            'outcome' => 'validation_failed',
        ];
    }

    private function resolveOutcome(
        SerialValidationResult $validation,
        ?string $selectedProduct,
        ?string $detectedProduct,
        ?string $crossModelHint,
    ): string {
        if ($crossModelHint !== null) {
            return 'mismatch';
        }

        if ($detectedProduct !== null
            && $selectedProduct !== null
            && $detectedProduct !== $selectedProduct) {
            return 'mismatch';
        }

        if (! $validation->allowsWorkflow()) {
            return 'validation_failed';
        }

        if ($detectedProduct === null && $validation->severity === SerialValidationSeverity::Warning) {
            return 'undetermined';
        }

        return 'match';
    }

    private function detectProduct(string $normalizedSerial): ?string
    {
        $matches = [];

        foreach (config('serial_validation.supported_products', []) as $product) {
            $result = $this->serialValidationService->validate($normalizedSerial, $product);

            if ($result->allowsWorkflow()) {
                $matches[] = $product;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function resolveDetectedModel(?string $detectedProduct, ?string $crossModelHint): ?DeviceModel
    {
        if ($detectedProduct !== null) {
            return $this->deviceModelAliasResolver->resolve($detectedProduct);
        }

        if ($crossModelHint !== null) {
            return $this->deviceModelAliasResolver->resolve($crossModelHint);
        }

        return null;
    }

    private function duplicateOwner(?Order $order, string $serialNumber): ?Order
    {
        if ($serialNumber === '' || $order === null) {
            return null;
        }

        return Order::query()
            ->where('serial_number', $serialNumber)
            ->whereKeyNot($order->id)
            ->first();
    }
}
