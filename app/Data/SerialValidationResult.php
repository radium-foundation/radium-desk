<?php

namespace App\Data;

use App\Enums\SerialValidationSeverity;
use App\Enums\SerialValidationStatus;

readonly class SerialValidationResult
{
    public function __construct(
        public SerialValidationStatus $status,
        public SerialValidationSeverity $severity,
        public string $normalizedSerial,
        public bool $corrected,
        public bool $requiresRadiumBoxVerification,
        public ?string $reason,
        public ?string $product,
    ) {}

    public function isValid(): bool
    {
        return $this->status->isValid();
    }

    public function isWarning(): bool
    {
        return $this->status->isWarning();
    }

    public function isInvalid(): bool
    {
        return $this->status->isInvalid();
    }

    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    public function isFail(): bool
    {
        return $this->severity->isFail();
    }

    public function allowsWorkflow(): bool
    {
        return $this->severity->allowsWorkflow();
    }

    public static function valid(
        string $normalizedSerial,
        string $product,
        bool $corrected = false,
        bool $requiresRadiumBoxVerification = false,
        ?string $reason = null,
    ): self {
        return new self(
            status: SerialValidationStatus::Valid,
            severity: SerialValidationSeverity::Pass,
            normalizedSerial: $normalizedSerial,
            corrected: $corrected,
            requiresRadiumBoxVerification: $requiresRadiumBoxVerification,
            reason: $reason,
            product: $product,
        );
    }

    public static function warning(string $normalizedSerial, string $product, string $reason): self
    {
        return new self(
            status: SerialValidationStatus::Warning,
            severity: SerialValidationSeverity::Warning,
            normalizedSerial: $normalizedSerial,
            corrected: false,
            requiresRadiumBoxVerification: true,
            reason: $reason,
            product: $product,
        );
    }

    public static function invalid(string $normalizedSerial, ?string $product, string $reason): self
    {
        return new self(
            status: SerialValidationStatus::Invalid,
            severity: SerialValidationSeverity::Fail,
            normalizedSerial: $normalizedSerial,
            corrected: false,
            requiresRadiumBoxVerification: false,
            reason: $reason,
            product: $product,
        );
    }

    public static function unsupported(string $normalizedSerial, ?string $product): self
    {
        return new self(
            status: SerialValidationStatus::Unsupported,
            severity: SerialValidationSeverity::Pass,
            normalizedSerial: $normalizedSerial,
            corrected: false,
            requiresRadiumBoxVerification: true,
            reason: 'No IRA validation rules are configured for this product.',
            product: $product,
        );
    }

    public static function pending(?string $product): self
    {
        return new self(
            status: SerialValidationStatus::Pending,
            severity: SerialValidationSeverity::Fail,
            normalizedSerial: '',
            corrected: false,
            requiresRadiumBoxVerification: false,
            reason: (string) config('serial_validation.placeholder_reason', 'Waiting for customer serial'),
            product: $product,
        );
    }
}
