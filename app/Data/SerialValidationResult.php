<?php

namespace App\Data;

use App\Enums\SerialValidationStatus;

readonly class SerialValidationResult
{
    public function __construct(
        public SerialValidationStatus $status,
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

    public function isInvalid(): bool
    {
        return $this->status->isInvalid();
    }

    public function isPending(): bool
    {
        return $this->status->isPending();
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
            normalizedSerial: $normalizedSerial,
            corrected: $corrected,
            requiresRadiumBoxVerification: $requiresRadiumBoxVerification,
            reason: $reason,
            product: $product,
        );
    }

    public static function invalid(string $normalizedSerial, ?string $product, string $reason): self
    {
        return new self(
            status: SerialValidationStatus::Invalid,
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
            normalizedSerial: '',
            corrected: false,
            requiresRadiumBoxVerification: false,
            reason: (string) config('serial_validation.placeholder_reason', 'Waiting for customer serial'),
            product: $product,
        );
    }
}
