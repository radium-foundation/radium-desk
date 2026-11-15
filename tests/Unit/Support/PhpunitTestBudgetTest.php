<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Tests\Support\PhpunitTestBudget;

class PhpunitTestBudgetTest extends TestCase
{
    public function test_budget_map_lists_only_the_six_measured_methods_at_sixty_seconds(): void
    {
        $budgets = PhpunitTestBudget::budgets();

        $this->assertCount(6, $budgets);
        $this->assertArrayNotHasKey('*', $budgets);
        foreach ($budgets as $testId => $spec) {
            $this->assertSame(60, $spec['seconds']);
            $this->assertNotSame('', $spec['reason']);
            $this->assertStringContainsString('::test_', $testId);
        }
        $this->assertNotNull(PhpunitTestBudget::budgetFor(
            'Tests\\Feature\\CentralWallet\\DeskFirstWalletRefundRevokeSpokeRoutingTest::test_radiumbox_desk_first_revoke_posts_central_wallet_audit_retirement'
        ));
        $this->assertNull(PhpunitTestBudget::budgetFor('Tests\\Unit\\Support\\PhpunitTestBudgetTest::test_budget_map_lists_only_the_six_measured_methods_at_sixty_seconds'));
    }

    public function test_one_second_budget_fails_sleep_and_records_the_sleep_frame(): void
    {
        if (! PhpunitTestBudget::canArmInProcess()) {
            $this->fail('BLOCKED: pcntl/posix is required to enforce a per-test budget.');
        }

        $log = sys_get_temp_dir().'/phpunit-budget-unit-'.bin2hex(random_bytes(4)).'.jsonl';
        $enforcer = new PhpunitTestBudget($log);
        $started = microtime(true);
        $caught = null;
        try {
            $enforcer->arm('Tests\\Example::test_sleep', 1, 'unit probe');
            sleep(5);
            $this->fail('sleep() returned without the budget failing the test.');
        } catch (AssertionFailedError $exception) {
            $caught = $exception;
        } finally {
            $enforcer->disarm();
        }

        $elapsed = microtime(true) - $started;
        $this->assertInstanceOf(AssertionFailedError::class, $caught);
        $this->assertStringContainsString('Per-test budget expired', $caught->getMessage());
        $this->assertStringContainsString('sleep', $caught->getMessage());
        $this->assertLessThan(4.0, $elapsed);
        $this->assertFileExists($log);
        $lines = file($log, FILE_IGNORE_NEW_LINES) ?: [];
        $expired = null;
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && ($decoded['event'] ?? '') === 'budget_expired') {
                $expired = $decoded;
            }
        }
        $this->assertIsArray($expired);
        $this->assertSame('Tests\\Example::test_sleep', $expired['test']);
        $this->assertSame('pcntl_alarm', $expired['mechanism']);
        $this->assertSame('NOT_PASSED', $expired['result']);
        $this->assertGreaterThan(0, $expired['elapsed_ms']);
    }
}
