<?php

declare(strict_types=1);

$path = $argv[1] ?? '';
$expectedOutcome = $argv[2] ?? '';

if ($path === '' || ! is_file($path)) {
    fwrite(STDERR, "status path missing\n");
    exit(1);
}

if ($expectedOutcome === '') {
    fwrite(STDERR, "expected outcome missing\n");
    exit(1);
}

$raw = file_get_contents($path);
$data = json_decode((string) $raw, true);

if (! is_array($data)) {
    fwrite(STDERR, "invalid status json\n");
    exit(1);
}

if ((int) ($data['version'] ?? 0) !== 1) {
    fwrite(STDERR, "unexpected version\n");
    exit(1);
}

if (($data['outcome'] ?? '') !== $expectedOutcome) {
    fwrite(STDERR, "unexpected outcome: ".($data['outcome'] ?? 'null')."\n");
    exit(1);
}

$forbiddenPatterns = [
    'passphrase',
    'password',
    'api_key',
    'apikey',
    'ssh',
    'secret',
    'sha256',
    '.gpg',
    '/root/',
    'remote_path',
];

$encoded = strtolower(json_encode($data, JSON_THROW_ON_ERROR));

foreach ($forbiddenPatterns as $pattern) {
    if (str_contains($encoded, strtolower($pattern))) {
        fwrite(STDERR, "forbidden pattern present: {$pattern}\n");
        exit(1);
    }
}

$allowedKeys = [
    'version',
    'generated_at',
    'outcome',
    'exit_code',
    'duration_seconds',
    'lock_acquired',
    'cloud_upload_enabled',
    'watchdog_accessible',
    'backup_id',
    'phase',
    'error_summary',
];

foreach (array_keys($data) as $key) {
    if (! in_array($key, $allowedKeys, true)) {
        fwrite(STDERR, "unexpected key: {$key}\n");
        exit(1);
    }
}

exit(0);
