<?php

namespace App\Data;

use Illuminate\Support\Carbon;

readonly class OrderIdentityRepairStatistics
{
    public function __construct(
        public int $totalRepaired,
        public int $duplicateConflicts,
        public int $waitingCustomerSerial,
        public int $validationFailures,
        public int $notFound,
        public ?Carbon $lastRepairRun,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toCacheArray(): array
    {
        return [
            'totalRepaired' => $this->totalRepaired,
            'duplicateConflicts' => $this->duplicateConflicts,
            'waitingCustomerSerial' => $this->waitingCustomerSerial,
            'validationFailures' => $this->validationFailures,
            'notFound' => $this->notFound,
            'lastRepairRun' => $this->lastRepairRun?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromCacheArray(array $data): self
    {
        $lastRepairRun = $data['lastRepairRun'] ?? null;

        return new self(
            totalRepaired: (int) ($data['totalRepaired'] ?? 0),
            duplicateConflicts: (int) ($data['duplicateConflicts'] ?? 0),
            waitingCustomerSerial: (int) ($data['waitingCustomerSerial'] ?? 0),
            validationFailures: (int) ($data['validationFailures'] ?? 0),
            notFound: (int) ($data['notFound'] ?? 0),
            lastRepairRun: is_string($lastRepairRun) && $lastRepairRun !== ''
                ? Carbon::parse($lastRepairRun)
                : null,
        );
    }
}
