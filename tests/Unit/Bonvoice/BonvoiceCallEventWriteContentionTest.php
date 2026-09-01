<?php

namespace Tests\Unit\Bonvoice;

use App\Services\Bonvoice\BonvoiceCallEventWriteContention;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class BonvoiceCallEventWriteContentionTest extends TestCase
{
    public function test_detects_mariadb_1020_from_driver_code(): void
    {
        $this->assertTrue(BonvoiceCallEventWriteContention::isRetryable(
            $this->queryException(1020, 'HY000', 'Record has changed since last read in table \'bonvoice_call_events\'; try restarting transaction'),
        ));
    }

    public function test_detects_deadlock_1213(): void
    {
        $this->assertTrue(BonvoiceCallEventWriteContention::isRetryable(
            $this->queryException(1213, '40001', 'Deadlock found when trying to get lock; try restarting transaction'),
        ));
    }

    public function test_detects_laravel_nested_transaction_deadlock_wrapper(): void
    {
        $query = $this->queryException(1020, 'HY000', 'Record has changed since last read in table \'bonvoice_call_events\'; try restarting transaction');

        $this->assertTrue(BonvoiceCallEventWriteContention::isRetryable(
            new DeadlockException($query->getMessage(), 0, $query),
        ));
    }

    public function test_does_not_treat_missing_call_id_or_duplicate_as_retryable(): void
    {
        $this->assertFalse(BonvoiceCallEventWriteContention::isRetryable(
            $this->queryException(1062, '23000', 'Duplicate entry for key bonvoice_call_events_call_id_leg_unique'),
        ));
        $this->assertFalse(BonvoiceCallEventWriteContention::isRetryable(
            new \RuntimeException('BonVoice webhook payload is missing callID.'),
        ));
    }

    private function queryException(int $driverCode, string $sqlState, string $detail): QueryException
    {
        $previous = new \PDOException(sprintf(
            'SQLSTATE[%s]: General error: %d %s',
            $sqlState,
            $driverCode,
            $detail,
        ));
        $previous->errorInfo = [$sqlState, $driverCode, $detail];

        return new QueryException(
            'mysql',
            'update `bonvoice_call_events` set `call_type` = ? where `id` = ?',
            ['1', 1],
            $previous,
        );
    }
}
