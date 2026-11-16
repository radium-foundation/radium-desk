<?php

namespace App\Services\DashboardOemHandoff;

use App\Models\DashboardOemHandoffSession;
use App\Models\Order;
use App\Models\RdExpiryServiceReferenceSequence;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RdExpiryServiceReferenceSequenceAllocator
{
    private const MAX_COLLISION_ATTEMPTS = 64;

    private const DEADLOCK_ATTEMPTS = 3;

    public function __construct(
        private readonly RdExpiryServiceReferenceFormatter $formatter,
    ) {}

    /**
     * @return array{reference: string, sequence: int, expiry_date: string}
     */
    public function allocate(string $isoExpiryDate, ?int $excludeSessionId = null): array
    {
        $isoExpiryDate = $this->assertIsoDate($isoExpiryDate);

        $lastException = null;
        for ($attempt = 0; $attempt < self::DEADLOCK_ATTEMPTS; $attempt++) {
            try {
                return $this->allocateWithinTransaction($isoExpiryDate, $excludeSessionId);
            } catch (QueryException $exception) {
                if (! $this->isDeadlock($exception)) {
                    throw $exception;
                }

                $lastException = $exception;
            }
        }

        throw $lastException ?? ValidationException::withMessages([
            'service_reference' => 'Unable to allocate a unique service reference for this expiry date.',
        ]);
    }

    /**
     * @return array{reference: string, sequence: int, expiry_date: string}
     */
    private function allocateWithinTransaction(string $isoExpiryDate, ?int $excludeSessionId): array
    {
        return DB::transaction(function () use ($isoExpiryDate, $excludeSessionId): array {
            $this->ensureSequenceRow($isoExpiryDate);

            $locked = RdExpiryServiceReferenceSequence::query()
                ->whereDate('expiry_date', $isoExpiryDate)
                ->lockForUpdate()
                ->firstOrFail();

            $prefix = $this->formatter->datePrefixFromIsoDate($isoExpiryDate);
            $baseline = max(
                (int) $locked->last_allocated_sequence,
                $this->maxAllocatedSequenceForPrefix($prefix),
                $this->maxSuggestedSequenceForPrefix($prefix, $excludeSessionId),
            );

            for ($attempt = 0; $attempt < self::MAX_COLLISION_ATTEMPTS; $attempt++) {
                $next = $baseline + 1;
                $reference = $this->formatter->formatFromIsoDate($isoExpiryDate, $next);

                if ($this->referenceIsTaken($reference)) {
                    $baseline = $next;

                    continue;
                }

                $locked->update(['last_allocated_sequence' => $next]);

                return [
                    'reference' => $reference,
                    'sequence' => $next,
                    'expiry_date' => $isoExpiryDate,
                ];
            }

            throw ValidationException::withMessages([
                'service_reference' => 'Unable to allocate a unique service reference for this expiry date.',
            ]);
        });
    }

    private function isDeadlock(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? '';

        return in_array((string) $sqlState, ['40001', '1213'], true)
            || str_contains(strtolower($exception->getMessage()), 'deadlock');
    }

    private function ensureSequenceRow(string $isoExpiryDate): RdExpiryServiceReferenceSequence
    {
        $existing = RdExpiryServiceReferenceSequence::query()
            ->whereDate('expiry_date', $isoExpiryDate)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        try {
            return RdExpiryServiceReferenceSequence::query()->create([
                'expiry_date' => $isoExpiryDate,
                'last_allocated_sequence' => 0,
            ]);
        } catch (UniqueConstraintViolationException|QueryException $exception) {
            $existing = RdExpiryServiceReferenceSequence::query()
                ->whereDate('expiry_date', $isoExpiryDate)
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            throw $exception;
        }
    }

    private function assertIsoDate(string $isoDate): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $isoDate) !== 1) {
            throw ValidationException::withMessages([
                'expiry_date' => 'Expiry date must be an ISO date (Y-m-d).',
            ]);
        }

        return $isoDate;
    }

    private function referenceIsTaken(string $reference): bool
    {
        return Order::query()
            ->withoutGlobalScopes()
            ->where('transaction_id', $reference)
            ->exists();
    }

    private function maxAllocatedSequenceForPrefix(string $prefix): int
    {
        $max = 0;
        $pattern = $prefix.'%';

        $references = Order::query()
            ->withoutGlobalScopes()
            ->where('transaction_id', 'like', $pattern)
            ->pluck('transaction_id');

        foreach ($references as $reference) {
            $parsed = $this->formatter->parseCanonicalReference((string) $reference);
            if ($parsed === null) {
                continue;
            }
            $max = max($max, (int) $parsed['nn']);
        }

        return $max;
    }

    private function maxSuggestedSequenceForPrefix(string $prefix, ?int $excludeSessionId): int
    {
        $max = 0;

        $query = DashboardOemHandoffSession::query()
            ->whereNotNull('suggested_service_reference')
            ->where('suggested_service_reference', 'like', $prefix.'%');

        if ($excludeSessionId !== null) {
            $query->where('id', '!=', $excludeSessionId);
        }

        foreach ($query->pluck('suggested_service_reference') as $reference) {
            $parsed = $this->formatter->parseCanonicalReference((string) $reference);
            if ($parsed === null) {
                continue;
            }
            $max = max($max, (int) $parsed['nn']);
        }

        return $max;
    }
}