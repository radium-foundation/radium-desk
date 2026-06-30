<?php

namespace App\Services\SerialValidation;

use App\Data\SerialValidationResult;
use App\Models\Order;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\SerialValidation\Contracts\ProductSerialValidator;
use App\Services\SerialValidation\Validators\Fm220SerialValidator;
use App\Services\SerialValidation\Validators\Marc11SerialValidator;
use App\Services\SerialValidation\Validators\Mfs110SerialValidator;
use App\Services\SerialValidation\Validators\Mis100SerialValidator;
use App\Services\SerialValidation\Validators\MsoE3SerialValidator;
use App\Services\SerialValidation\Validators\Pb1000SerialValidator;
use App\Support\DeviceModelFormatter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class SerialValidationService
{
    /** @var array<string, ProductSerialValidator> */
    private array $validatorsByProduct;

    public function __construct(
        private readonly AuditLogService $auditLogService,
        Mfs110SerialValidator $mfs110SerialValidator,
        Mis100SerialValidator $mis100SerialValidator,
        MsoE3SerialValidator $msoE3SerialValidator,
        Fm220SerialValidator $fm220SerialValidator,
        Pb1000SerialValidator $pb1000SerialValidator,
        Marc11SerialValidator $marc11SerialValidator,
    ) {
        $this->validatorsByProduct = [];

        foreach ([
            $mfs110SerialValidator,
            $mis100SerialValidator,
            $msoE3SerialValidator,
            $fm220SerialValidator,
            $pb1000SerialValidator,
            $marc11SerialValidator,
        ] as $validator) {
            $this->validatorsByProduct[$validator->product()] = $validator;
        }
    }

    public function validate(string $serial, ?string $product): SerialValidationResult
    {
        $normalizedInput = strtoupper(trim($serial));
        $resolvedProduct = $this->resolveProductName($product);

        if ($resolvedProduct === null) {
            return SerialValidationResult::unsupported($normalizedInput, null);
        }

        $validator = $this->validatorsByProduct[$resolvedProduct] ?? null;

        if ($validator === null) {
            return SerialValidationResult::unsupported($normalizedInput, $resolvedProduct);
        }

        return $validator->validate($serial);
    }

    public function validateForOrder(string $serial, Order $order): SerialValidationResult
    {
        return $this->validate($serial, $this->resolveProductFromOrder($order));
    }

    public function resolveProductFromOrder(Order $order): ?string
    {
        return $this->resolveProductName($order->device_model)
            ?? $this->resolveProductName($order->product_name);
    }

    public function resolveProductName(?string $product): ?string
    {
        if (! filled($product)) {
            return null;
        }

        $shortDisplay = DeviceModelFormatter::shortDisplay($product);

        if ($shortDisplay !== null && isset($this->validatorsByProduct[$shortDisplay])) {
            return $shortDisplay;
        }

        $normalized = strtoupper(trim($product));

        if (isset($this->validatorsByProduct[$normalized])) {
            return $normalized;
        }

        foreach (array_keys($this->validatorsByProduct) as $supportedProduct) {
            if (str_starts_with($normalized, str_replace(' ', '', $supportedProduct))) {
                return $supportedProduct;
            }
        }

        return $shortDisplay ?? trim($product);
    }

    /**
     * @throws ValidationException
     */
    public function assertValidForOrder(string $serial, Order $order): SerialValidationResult
    {
        $result = $this->validateForOrder($serial, $order);

        if ($result->isInvalid()) {
            throw ValidationException::withMessages([
                'serial_number' => $result->reason ?? 'The serial number is invalid for this product.',
            ]);
        }

        return $result;
    }

    /**
     * @throws ValidationException
     */
    public function assertValid(string $serial, ?string $product): SerialValidationResult
    {
        $result = $this->validate($serial, $product);

        if ($result->isInvalid()) {
            throw ValidationException::withMessages([
                'serial_number' => $result->reason ?? 'The serial number is invalid for this product.',
            ]);
        }

        return $result;
    }

    public function recordIraCorrection(Order $order, string $originalSerial, string $correctedSerial, User $actor): void
    {
        $this->auditLogService->log(
            userId: $this->resolveAutomationUserId($actor),
            event: 'serial.corrected_by_ira',
            auditable: $order,
            oldValues: [
                'serial_number' => $originalSerial,
            ],
            newValues: [
                'serial_number' => $correctedSerial,
                'note' => 'Corrected by IRA',
            ],
        );
    }

    private function resolveAutomationUserId(User $fallbackActor): int
    {
        $systemEmail = (string) config('cashfree.system_user_email');

        if ($systemEmail === '') {
            return $fallbackActor->id;
        }

        return Cache::remember(
            'serial_validation.automation_user_id.'.$systemEmail,
            now()->addDay(),
            function () use ($systemEmail, $fallbackActor): int {
                $systemUser = User::query()->where('email', $systemEmail)->value('id');

                return $systemUser ?? $fallbackActor->id;
            },
        );
    }
}
