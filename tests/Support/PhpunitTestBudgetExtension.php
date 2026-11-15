<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\ErroredSubscriber;
use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\FailedSubscriber;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\Test\PreparationErrored;
use PHPUnit\Event\Test\PreparationErroredSubscriber;
use PHPUnit\Event\Test\PreparationFailed;
use PHPUnit\Event\Test\PreparationFailedSubscriber;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\Test\SkippedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Arms the explicit per-test budget before setUp so a hang in either setUp
 * or the test method is visible and failed closed.
 */
final class PhpunitTestBudgetExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $log = getenv('PHPUNIT_BUDGET_LOG');
        if ($log === false || $log === '') {
            $log = sys_get_temp_dir().'/phpunit-test-budget.jsonl';
        }
        $enforcer = new PhpunitTestBudget($log);
        $facade->registerSubscriber(new PhpunitTestBudgetPreparationSubscriber($enforcer));
        $facade->registerSubscriber(new PhpunitTestBudgetFinishedSubscriber($enforcer));
        $facade->registerSubscriber(new PhpunitTestBudgetFailedSubscriber($enforcer));
        $facade->registerSubscriber(new PhpunitTestBudgetErroredSubscriber($enforcer));
        $facade->registerSubscriber(new PhpunitTestBudgetSkippedSubscriber($enforcer));
        $facade->registerSubscriber(new PhpunitTestBudgetPreparationFailedSubscriber($enforcer));
        $facade->registerSubscriber(new PhpunitTestBudgetPreparationErroredSubscriber($enforcer));
    }
}

final readonly class PhpunitTestBudgetPreparationSubscriber implements PreparationStartedSubscriber
{
    public function __construct(private PhpunitTestBudget $enforcer) {}

    public function notify(PreparationStarted $event): void
    {
        $testId = $event->test()->id();
        $budget = PhpunitTestBudget::budgetFor($testId);
        if ($budget === null) {
            return;
        }
        $this->enforcer->arm($testId, $budget['seconds'], $budget['reason']);
    }
}

final readonly class PhpunitTestBudgetFinishedSubscriber implements FinishedSubscriber
{
    public function __construct(private PhpunitTestBudget $enforcer) {}

    public function notify(Finished $event): void
    {
        $this->enforcer->failClosedIfExpired();
        $this->enforcer->disarm();
    }
}

final readonly class PhpunitTestBudgetFailedSubscriber implements FailedSubscriber
{
    public function __construct(private PhpunitTestBudget $enforcer) {}

    public function notify(Failed $event): void
    {
        $this->enforcer->disarm();
    }
}

final readonly class PhpunitTestBudgetErroredSubscriber implements ErroredSubscriber
{
    public function __construct(private PhpunitTestBudget $enforcer) {}

    public function notify(Errored $event): void
    {
        $this->enforcer->disarm();
    }
}

final readonly class PhpunitTestBudgetSkippedSubscriber implements SkippedSubscriber
{
    public function __construct(private PhpunitTestBudget $enforcer) {}

    public function notify(Skipped $event): void
    {
        $this->enforcer->disarm();
    }
}

final readonly class PhpunitTestBudgetPreparationFailedSubscriber implements PreparationFailedSubscriber
{
    public function __construct(private PhpunitTestBudget $enforcer) {}

    public function notify(PreparationFailed $event): void
    {
        $this->enforcer->disarm();
    }
}

final readonly class PhpunitTestBudgetPreparationErroredSubscriber implements PreparationErroredSubscriber
{
    public function __construct(private PhpunitTestBudget $enforcer) {}

    public function notify(PreparationErrored $event): void
    {
        $this->enforcer->disarm();
    }
}
