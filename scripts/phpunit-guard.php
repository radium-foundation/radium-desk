#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Support\Testing\PhpunitGuard;

$projectRoot = dirname(__DIR__);

require $projectRoot.'/vendor/autoload.php';

$argv = array_slice($GLOBALS['argv'], 1);
$checkOnly = false;
$invokedVia = 'scripts/phpunit-guard.php';

foreach ($argv as $index => $arg) {
    if ($arg === '--guard-check-only') {
        $checkOnly = true;
        unset($argv[$index]);
    }
    if (str_starts_with($arg, '--invoked-via=')) {
        $invokedVia = substr($arg, strlen('--invoked-via='));
        unset($argv[$index]);
    }
}

$argv = array_values($argv);

$guard = new PhpunitGuard($projectRoot);
$verdict = $guard->evaluate($argv);

if (! $verdict['allowed']) {
    $guard->appendBlockedLog($verdict, $argv, $invokedVia);
    fwrite(STDERR, sprintf(
        "PHPUnit guard blocked (%s): %s\nUse bin/phpunit-safe with a focused target, or grant Owner authorization per docs/PHPUNIT_FULL_SUITE_AUTHORIZATION.md\n",
        $verdict['scope'],
        $verdict['reason'],
    ));

    exit($verdict['code']);
}

if ($checkOnly) {
    fwrite(STDOUT, sprintf("PHPUnit guard allow (%s): %s\n", $verdict['scope'], $verdict['reason']));
    exit(0);
}

$phpunit = $projectRoot.'/vendor/bin/phpunit';
if (! is_executable($phpunit)) {
    fwrite(STDERR, "PHPUnit binary not found or not executable: {$phpunit}\n");
    exit(1);
}

$parts = array_map(static fn (string $p): string => escapeshellarg($p), array_merge([$phpunit], $argv));
$command = implode(' ', $parts);
passthru($command, $exitCode);
exit((int) $exitCode);
