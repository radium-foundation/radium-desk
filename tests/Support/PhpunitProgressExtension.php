<?php

namespace Tests\Support;

use PHPUnit\Event\InvalidArgumentException;
use PHPUnit\Event\Test\Finished as TestFinished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
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
        $facade->registerSubscriber(new PhpunitProgressPreparationStartedSubscriber($writer));
        $facade->registerSubscriber(new PhpunitProgressPreparedSubscriber($writer));
        $facade->registerSubscriber(new PhpunitProgressFinishedSubscriber($writer));
    }
}

final class PhpunitProgressWriter
{
    /** @var array<string, float> */
    private array $startedAt = [];

    public function __construct(private string $path) {}

    public function append(string $phase, string $testId): void
    {
        $now = microtime(true);
        $payload = [
            'ts' => self::timestamp($now),
            'event' => $phase,
            'test' => $testId,
        ];
        if ($phase === 'preparation_started' || $phase === 'prepared') {
            if (! isset($this->startedAt[$testId])) {
                $this->startedAt[$testId] = $now;
            }
            $this->writeOpen($testId, $this->startedAt[$testId]);
        }
        if ($phase === 'finished') {
            $started = $this->startedAt[$testId] ?? null;
            if ($started !== null) {
                $payload['elapsed_ms'] = (int) round(($now - $started) * 1000);
            }
            $this->clearOpen();
            unset($this->startedAt[$testId]);
        }

        $line = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            return;
        }

        file_put_contents($this->path, $line."\n", FILE_APPEND | LOCK_EX);
    }

    private function writeOpen(string $testId, float $now): void
    {
        $line = json_encode([
            'test' => $testId,
            'started_unix' => $now,
            'started_at' => self::timestamp($now),
            'pid' => getmypid(),
        ], JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            return;
        }
        file_put_contents($this->openPath(), $line, LOCK_EX);
    }

    private function clearOpen(): void
    {
        $path = $this->openPath();
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function openPath(): string
    {
        return $this->path.'.open';
    }

    private static function timestamp(float $now): string
    {
        $seconds = (int) floor($now);
        $milliseconds = (int) round(($now - $seconds) * 1000);
        if ($milliseconds === 1000) {
            $seconds++;
            $milliseconds = 0;
        }

        return gmdate('Y-m-d\TH:i:s', $seconds).sprintf('.%03d+00:00', $milliseconds);
    }
}

final readonly class PhpunitProgressPreparationStartedSubscriber implements PreparationStartedSubscriber
{
    public function __construct(private PhpunitProgressWriter $writer) {}

    /**
     * @throws InvalidArgumentException
     */
    public function notify(PreparationStarted $event): void
    {
        $this->writer->append('preparation_started', $event->test()->id());
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
