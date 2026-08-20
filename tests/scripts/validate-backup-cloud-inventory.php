<?php

declare(strict_types=1);

$path = $argv[1] ?? '';

if ($path === '' || ! is_file($path)) {
    fwrite(STDERR, "inventory path missing\n");
    exit(1);
}

$raw = file_get_contents($path);
$data = json_decode((string) $raw, true);

if (! is_array($data)) {
    fwrite(STDERR, "invalid inventory json\n");
    exit(1);
}

if ((int) ($data['version'] ?? 0) !== 1) {
    fwrite(STDERR, "unexpected version\n");
    exit(1);
}

$entries = $data['entries'] ?? null;

if (! is_array($entries) || count($entries) !== 2) {
    fwrite(STDERR, "expected 2 completed entries\n");
    exit(1);
}

$requiredKeys = ['backup_id', 'timestamp_utc', 'total_size_bytes', 'manifest_present', 'upload_complete'];
$forbiddenKeys = ['remote_path', 'remote_host', 'filename', 'sha256', 'encryption', 'artifacts'];

foreach ($entries as $entry) {
    if (! is_array($entry)) {
        fwrite(STDERR, "entry is not an array\n");
        exit(1);
    }

    foreach ($requiredKeys as $key) {
        if (! array_key_exists($key, $entry)) {
            fwrite(STDERR, "missing key {$key}\n");
            exit(1);
        }
    }

    foreach ($forbiddenKeys as $forbidden) {
        if (array_key_exists($forbidden, $entry)) {
            fwrite(STDERR, "forbidden key present: {$forbidden}\n");
            exit(1);
        }
    }

    if ($entry['manifest_present'] !== true || $entry['upload_complete'] !== true) {
        fwrite(STDERR, "expected completed flags true\n");
        exit(1);
    }
}

if (($entries[0]['backup_id'] ?? '') !== '20260820T083001Z') {
    fwrite(STDERR, "expected newest entry first\n");
    exit(1);
}

exit(0);
