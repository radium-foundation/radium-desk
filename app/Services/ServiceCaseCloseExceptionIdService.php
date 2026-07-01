<?php

namespace App\Services;

use App\Models\ServiceCaseCloseException;
use Illuminate\Support\Facades\DB;

class ServiceCaseCloseExceptionIdService
{
    public function generate(): string
    {
        return DB::transaction(function (): string {
            $date = now()->format('Ymd');
            $prefix = "EXC-{$date}-";

            $latestSequence = ServiceCaseCloseException::query()
                ->where('exception_id', 'like', $prefix.'%')
                ->lockForUpdate()
                ->pluck('exception_id')
                ->map(fn (string $exceptionId): int => $this->extractSequence($exceptionId))
                ->max();

            $sequence = ($latestSequence ?? 0) + 1;

            return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
        });
    }

    private function extractSequence(string $exceptionId): int
    {
        if (preg_match('/^EXC-\d{8}-(\d+)$/i', $exceptionId, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }
}
