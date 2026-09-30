<?php

namespace Tests\Unit\CentralWallet;

use App\CentralWallet\Application\BalanceMigrationStateMachine;
use App\CentralWallet\Domain\Enums\BalanceMigrationStatus;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BalanceMigrationStateMachineTest extends TestCase
{
    private BalanceMigrationStateMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->machine = new BalanceMigrationStateMachine;
    }

    #[DataProvider('validTransitionsProvider')]
    public function test_allows_valid_transition(BalanceMigrationStatus $from, BalanceMigrationStatus $to): void
    {
        $this->machine->assertCanTransition($from, $to);
        $this->addToAssertionCount(1);
    }

    public static function validTransitionsProvider(): array
    {
        return [
            [BalanceMigrationStatus::Initiated, BalanceMigrationStatus::Prepared],
            [BalanceMigrationStatus::Initiated, BalanceMigrationStatus::Aborted],
            [BalanceMigrationStatus::Prepared, BalanceMigrationStatus::CentralCredited],
            [BalanceMigrationStatus::Prepared, BalanceMigrationStatus::Aborted],
            [BalanceMigrationStatus::CentralCredited, BalanceMigrationStatus::SourceRetired],
            [BalanceMigrationStatus::CentralCredited, BalanceMigrationStatus::Compensating],
            [BalanceMigrationStatus::SourceRetired, BalanceMigrationStatus::Reconciled],
            [BalanceMigrationStatus::Compensating, BalanceMigrationStatus::Aborted],
        ];
    }

    public function test_rejects_invalid_transition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->machine->assertCanTransition(BalanceMigrationStatus::Initiated, BalanceMigrationStatus::Reconciled);
    }

    public function test_terminal_states_have_no_outgoing_transitions(): void
    {
        $this->assertSame([], $this->machine->allowedTransitions(BalanceMigrationStatus::Reconciled));
        $this->assertSame([], $this->machine->allowedTransitions(BalanceMigrationStatus::Aborted));
    }
}
