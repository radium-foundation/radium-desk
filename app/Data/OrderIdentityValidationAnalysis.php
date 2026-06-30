<?php

namespace App\Data;

use App\Enums\OrderIdentityValidationFailureGroup;
use App\Enums\OrderIdentityValidationRecommendation;

readonly class OrderIdentityValidationAnalysis
{
    public function __construct(
        public int $internalId,
        public string $externalOrderId,
        public ?string $productName,
        public ?string $deviceModel,
        public ?string $serialNumber,
        public ?string $validatorClass,
        public bool $validationPassed,
        public ?string $failureReason,
        public ?string $ruleFailed,
        public string $radiumBoxSyncLabel,
        public string $automationStatusLabel,
        public ?string $assigneeName,
        public ?string $assigneeRole,
        public OrderIdentityValidationRecommendation $recommendation,
        public OrderIdentityValidationFailureGroup $failureGroup,
    ) {}

    public function validatorResultLabel(): string
    {
        return $this->validationPassed ? 'PASS' : 'FAIL';
    }
}
