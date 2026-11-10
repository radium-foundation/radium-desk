<?php

namespace Tests\Support;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * P-10-31 — when PHPUNIT_MEMORY_PROBE_FILE is set, append per-test memory samples.
 */
final class PhpunitMemoryProbeExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $path = getenv('PHPUNIT_MEMORY_PROBE_FILE');
        if ($path === false || $path === '') {
            return;
        }

        $writer = new PhpunitMemoryProbeWriter($path);
        $facade->registerSubscriber(new PhpunitMemoryProbePreparedSubscriber($writer));
        $facade->registerSubscriber(new PhpunitMemoryProbeFinishedSubscriber($writer));
    }
}

final class PhpunitMemoryProbeWriter
{
    public function __construct(private string $path) {}

    public function append(string $phase, string $testId): void
    {
        $rssKb = $this->rssKb();

        $line = json_encode([
            'ts' => gmdate('c'),
            'phase' => $phase,
            'test' => $testId,
            'mem_usg' => memory_get_usage(false),
            'mem_usg_real' => memory_get_usage(true),
            'mem_peak' => memory_get_peak_usage(false),
            'mem_peak_real' => memory_get_peak_usage(true),
            'rss_kb' => $rssKb,
            'ini_memory_limit' => ini_get('memory_limit'),
        ], JSON_UNESCAPED_SLASHES);

        if ($line === false) {
            return;
        }

        file_put_contents($this->path, $line."\n", FILE_APPEND | LOCK_EX);
    }

    private function rssKb(): ?int
    {
        $pid = getmypid();
        if ($pid === false) {
            return null;
        }

        $out = shell_exec('ps -o rss= -p '.(int) $pid.' 2>/dev/null');
        if ($out === null || $out === '') {
            return null;
        }

        return (int) trim($out);
    }
}

final readonly class PhpunitMemoryProbePreparedSubscriber implements PreparedSubscriber
{
    public function __construct(private PhpunitMemoryProbeWriter $writer) {}

    public function notify(Prepared $event): void
    {
        $this->writer->append('prepared', $event->test()->id());
    }
}

final readonly class PhpunitMemoryProbeFinishedSubscriber implements FinishedSubscriber
{
    public function __construct(private PhpunitMemoryProbeWriter $writer) {}

    public function notify(Finished $event): void
    {
        gc_collect_cycles();
        $this->writer->append('finished', $event->test()->id());
    }
}
