<?php

namespace App\Services\Bonvoice;

use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

/**
 * MariaDB 1020 ("record has changed since last read") happens when two
 * transactions snapshot-read the same bonvoice_call_events row, then one
 * commits an UPDATE and the other tries to UPDATE inside REPEATABLE READ.
 *
 * BonVoice sends overlapping lifecycle POSTs (callType 0 / 0.5 / 1 / hangup)
 * for the same call_id+leg, each in its own HTTP-scoped outbox transaction.
 *
 * Retrying the UPDATE inside the same transaction cannot recover: the read
 * view is still stale. The persist unit of work must roll back and start a
 * new transaction. lockForUpdate on call_id+leg serialises writers for that
 * identity only — unrelated calls are not blocked.
 *
 * Laravel DetectsConcurrencyErrors treats the 1020 message as a concurrency
 * error. Nested transactions (RefreshDatabase tests, or any outer unit of
 * work) convert that QueryException into DeadlockException. Retry both.
 */
final class BonvoiceCallEventWriteContention
{
    public const DRIVER_RECORD_CHANGED = 1020;

    public const DRIVER_DEADLOCK = 1213;

    public static function isRetryable(Throwable $exception): bool
    {
        if ($exception instanceof QueryException || $exception instanceof PDOException) {
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);

            if (in_array($driverCode, [self::DRIVER_RECORD_CHANGED, self::DRIVER_DEADLOCK], true)) {
                return true;
            }
        }

        $message = $exception->getMessage();

        if (str_contains($message, 'Record has changed since last read')
            || str_contains($message, 'Deadlock found when trying to get lock')) {
            return true;
        }

        $previous = $exception->getPrevious();

        return $previous instanceof Throwable && self::isRetryable($previous);
    }
}
