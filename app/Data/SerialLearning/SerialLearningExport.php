<?php

namespace App\Data\SerialLearning;

readonly class SerialLearningExport
{
    /**
     * @param  list<array<string, mixed>>  $validSerials
     * @param  list<array<string, mixed>>  $failedValidations
     * @param  list<array<string, mixed>>  $correctedHistory
     * @param  list<array<string, mixed>>  $productMapping
     * @param  array<string, int>  $validationReasons
     */
    public function __construct(
        public string $exportedAt,
        public int $validSerialCount,
        public array $validSerials,
        public int $failedValidationCount,
        public array $failedValidations,
        public int $correctedHistoryCount,
        public array $correctedHistory,
        public array $productMapping,
        public array $validationReasons,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'exported_at' => $this->exportedAt,
            'valid_serials' => [
                'count' => $this->validSerialCount,
                'samples' => $this->validSerials,
            ],
            'failed_validations' => [
                'count' => $this->failedValidationCount,
                'samples' => $this->failedValidations,
            ],
            'corrected_serial_history' => [
                'count' => $this->correctedHistoryCount,
                'samples' => $this->correctedHistory,
            ],
            'product_mapping' => $this->productMapping,
            'validation_reasons' => $this->validationReasons,
        ];
    }
}
