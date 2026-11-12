#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * One-shot Owner grant file for an authorized full PHPUnit run (P-12-40).
 *
 * Usage:
 *   php scripts/grant-phpunit-full-suite-auth.php \
 *     --by="Owner" \
 *     --prompt-id="RadiumDesk-P-12-40" \
 *     --ttl-minutes=120
 */
$projectRoot = dirname(__DIR__);

$options = getopt('', ['by:', 'prompt-id:', 'ttl-minutes:', 'sha::']);
$by = $options['by'] ?? '';
$promptId = $options['prompt-id'] ?? '';
$ttl = isset($options['ttl-minutes']) ? (int) $options['ttl-minutes'] : 0;
$sha = $options['sha'] ?? null;

if ($by === '' || $promptId === '' || $ttl < 1) {
    fwrite(STDERR, "Required: --by= --prompt-id= --ttl-minutes= (positive int)\n");
    exit(2);
}

if ($sha === null || $sha === '') {
    $headFile = $projectRoot.'/.git/HEAD';
    if (! is_readable($headFile)) {
        fwrite(STDERR, "Cannot resolve git HEAD; pass --sha= explicitly.\n");
        exit(2);
    }
    $head = trim((string) file_get_contents($headFile));
    if (str_starts_with($head, 'ref: ')) {
        $refPath = $projectRoot.'/.git/'.substr($head, 5);
        $sha = is_readable($refPath) ? trim((string) file_get_contents($refPath)) : '';
    } else {
        $sha = $head;
    }
}

if (! preg_match('/^[0-9a-f]{40}$/i', (string) $sha)) {
    fwrite(STDERR, "Invalid sha.\n");
    exit(2);
}

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$expires = $now->modify(sprintf('+%d minutes', $ttl));

$payload = [
    'authorized_by' => $by,
    'authorized_at' => $now->format(DateTimeImmutable::ATOM),
    'expires_at' => $expires->format(DateTimeImmutable::ATOM),
    'prompt_id' => $promptId,
    'sha' => strtolower((string) $sha),
];

$dir = $projectRoot.'/storage/app/tmp';
if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
    fwrite(STDERR, "Cannot create {$dir}\n");
    exit(2);
}

$path = $dir.'/phpunit-full-suite-auth.json';
file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

fwrite(STDOUT, "Wrote {$path} (expires {$payload['expires_at']})\n");
exit(0);
