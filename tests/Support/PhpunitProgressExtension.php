<?php

namespace Tests\Support;

use PHPUnit\Event\InvalidArgumentException;
use PHPUnit\Event\Test\Finished as TestFinished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Writes timestamped test lifecycle events when PHPUNIT_PROGRESS_FILE is set.
 * Used by full-suite wrappers for progress independent of PHPUnit stdout buffering.
 */
final class PhpunitProgressExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $path = getenv('PHPUNIT_PROGRESS_FILE');
        if ($path === false || $path === '') {
            return;
        }

        $writer = new PhpunitProgressWriter($path);
        $facade->registerSubscriber(new PhpunitProgressPreparedSubscriber($writer));
        $facade->registerSubscriber(new PhpunitProgressFinishedSubscriber($writer));
    }
}

final class PhpunitProgressWriter
{
    public function __construct(private string $path) {}

    public function append(string $phase, string $testId): void
    {
        $line = json_encode([
            'ts' => gmdate('c'),
            'event' => $phase,
            'test' => $testId,
        ], JSON_UNESCAPED_SLASHES);

        if ($line === false) {
            return;
        }

        file_put_contents($this->path, $line."\n", FILE_APPEND | LOCK_EX);
    }
}

final readonly class PhpunitProgressPreparedSubscriber implements PreparedSubscriber
{
    public function __construct(private PhpunitProgressWriter $writer) {}

    /**
     * @throws InvalidArgumentException
     */
    public function notify(Prepared $event): void
    {
        $this->writer->append('prepared', $event->test()->id());
    }
}

final readonly class PhpunitProgressFinishedSubscriber implements FinishedSubscriber
{
    public function __construct(private PhpunitProgressWriter $writer) {}

    /**
     * @throws InvalidArgumentException
     */
    public function notify(TestFinished $event): void
    {
        $this->writer->append('finished', $event->test()->id());
    }
}
