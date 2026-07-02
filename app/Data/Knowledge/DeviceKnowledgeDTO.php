<?php

namespace App\Data\Knowledge;

use Illuminate\Support\Carbon;

readonly class DeviceKnowledgeDTO
{
    /**
     * @param  list<array{reference: string, title: string, status: string}>  $repairHistory
     * @param  list<string>  $failureHistory
     * @param  list<string>  $partsReplaced
     * @param  list<array{technician: string, reference: string, occurred_at: Carbon|null}>  $technicianHistory
     * @param  list<array{serial: string, order_id: string}>  $serialHistory
     */
    public function __construct(
        public ?string $model,
        public ?string $category,
        public ?string $variant,
        public bool $serialAvailable,
        public int $previousRepairsOnSerial,
        public int $previousRepairsOnModel,
        public array $repairHistory,
        public array $failureHistory,
        public array $partsReplaced,
        public array $technicianHistory,
        public array $serialHistory,
    ) {}
}
