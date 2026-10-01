<?php

namespace Tests\Unit\CentralWallet;

use App\CentralWallet\Application\RefundMigrationStateMachine;
use App\CentralWallet\Domain\Enums\RefundMigrationStatus;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RefundMigrationStateMachineTest extends TestCase
{
    private RefundMigrationStateMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->machine = new RefundMigrationStateMachine;
    }

    #[DataProvider('validTransitionsProvider')]
    public function test_valid_transitions(RefundMigrationStatus $from, RefundMigrationStatus $to): void
    {
        $this->machine->assertCanTransition($from, $to);
        $this->addToAssertionCount(1);
    }

    public static function validTransitionsProvider(): array
    {
        return [
            [RefundMigrationStatus::Pending, RefundMigrationStatus::Prepared],
            [RefundMigrationStatus::Prepared, RefundMigrationStatus::SourceDebitPending],
            [RefundMigrationStatus::Prepared, RefundMigrationStatus::CwCreditPending],
            [RefundMigrationStatus::SourceDebitPending, RefundMigrationStatus::SourceDebited],
            [RefundMigrationStatus::CwCreditPending, RefundMigrationStatus::CwCredited],
            [RefundMigrationStatus::CwCredited, RefundMigrationStatus::Reconciled],
            [RefundMigrationStatus::Reconciled, RefundMigrationStatus::Compensating],
            [RefundMigrationStatus::Compensating, RefundMigrationStatus::Compensated],
            [RefundMigrationStatus::Compensated, RefundMigrationStatus::Reversed],
        ];
    }

    public function test_invalid_transition_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->machine->assertCanTransition(RefundMigrationStatus::Pending, RefundMigrationStatus::Reconciled);
    }
}
