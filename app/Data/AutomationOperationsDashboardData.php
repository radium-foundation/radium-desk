<?php

namespace App\Data;

use Illuminate\Support\Carbon;

readonly class AutomationOperationsDashboardData
{
    /**
     * @param  array<string, int>  $healthCounts
     * @param  list<array<string, mixed>>  $waitingForCustomerSerialQueue
     * @param  list<array<string, mixed>>  $duplicateSerialConflicts
     * @param  list<array<string, mixed>>  $radiumBoxNotFoundQueue
     * @param  list<array<string, mixed>>  $recentAutomationEvents
     * @param  array<string, int>  $validationByProduct
     * @param  array<string, int>  $validationByValidatorRule
     * @param  array<string, int>  $validationByCategory
     */
    public function __construct(
        public array $healthCounts,
        public array $waitingForCustomerSerialQueue,
        public array $duplicateSerialConflicts,
        public array $radiumBoxNotFoundQueue,
        public array $recentAutomationEvents,
        public OrderIdentityRepairStatistics $repairStatistics,
        public array $validationByProduct,
        public array $validationByValidatorRule,
        public array $validationByCategory,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toCacheArray(): array
    {
        return [
            'healthCounts' => $this->healthCounts,
            'waitingForCustomerSerialQueue' => $this->waitingForCustomerSerialQueue,
            'duplicateSerialConflicts' => $this->duplicateSerialConflicts,
            'radiumBoxNotFoundQueue' => $this->radiumBoxNotFoundQueue,
            'recentAutomationEvents' => array_map(
                fn (array $event): array => [
                    ...$event,
                    'occurred_at' => ($event['occurred_at'] ?? null) instanceof Carbon
                        ? $event['occurred_at']->toIso8601String()
                        : $event['occurred_at'],
                ],
                $this->recentAutomationEvents,
            ),
            'repairStatistics' => $this->repairStatistics->toCacheArray(),
            'validationByProduct' => $this->validationByProduct,
            'validationByValidatorRule' => $this->validationByValidatorRule,
            'validationByCategory' => $this->validationByCategory,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromCacheArray(array $data): self
    {
        $events = array_map(function (array $event): array {
            $occurredAt = $event['occurred_at'] ?? null;

            return [
                ...$event,
                'occurred_at' => is_string($occurredAt) && $occurredAt !== ''
                    ? Carbon::parse($occurredAt)
                    : $occurredAt,
            ];
        }, $data['recentAutomationEvents'] ?? []);

        return new self(
            healthCounts: $data['healthCounts'] ?? [],
            waitingForCustomerSerialQueue: $data['waitingForCustomerSerialQueue'] ?? [],
            duplicateSerialConflicts: $data['duplicateSerialConflicts'] ?? [],
            radiumBoxNotFoundQueue: $data['radiumBoxNotFoundQueue'] ?? [],
            recentAutomationEvents: $events,
            repairStatistics: OrderIdentityRepairStatistics::fromCacheArray($data['repairStatistics'] ?? []),
            validationByProduct: $data['validationByProduct'] ?? [],
            validationByValidatorRule: $data['validationByValidatorRule'] ?? [],
            validationByCategory: $data['validationByCategory'] ?? [],
        );
    }
}
