<?php

namespace App\Services;

use App\Models\ServiceCaseCloseException;
use Illuminate\Support\Facades\DB;

class ServiceCaseCloseExceptionIdService
{
    public function generateSerial(): string
    {
        return $this->generate('EXS');
    }

    public function generateReference(): string
    {
        return $this->generate('EXR');
    }

    private function generate(string $prefix): string
    {
        return DB::transaction(function () use ($prefix): string {
            $date = now()->format('Ymd');
            $fullPrefix = "{$prefix}-{$date}-";

            $latestSequence = ServiceCaseCloseException::query()
                ->where('exception_id', 'like', $fullPrefix.'%')
                ->lockForUpdate()
                ->pluck('exception_id')
                ->map(fn (string $exceptionId): int => $this->extractSequence($exceptionId, $prefix))
                ->max();

            $sequence = ($latestSequence ?? 0) + 1;

            return $fullPrefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
        });
    }

    private function extractSequence(string $exceptionId, string $prefix): int
    {
        $pattern = '/^'.preg_quote($prefix, '/').'-\d{8}-(\d+)$/i';

        if (preg_match($pattern, $exceptionId, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }
}
