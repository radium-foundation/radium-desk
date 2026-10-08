<?php

declare(strict_types=1);

/**
 * UAT-only: align spoke central_wallet_account_links to Desk authoritative rows
 * for explicit local_user_id allowlist. No production paths.
 *
 * Usage:
 *   php tools/audit/align-uat-spoke-account-links.php \
 *     --desk-env=/var/www/radium-desk-uat/.env \
 *     --spoke-env=/var/www/rdservice-in-uat/.env \
 *     --site-code=rdservice.in \
 *     --local-user-id=990001 --local-user-id=990002
 */

function parseEnvFile(string $path): array
{
    if (! is_readable($path)) {
        throw new RuntimeException("Env file not readable: {$path}");
    }

    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $values[trim($key)] = trim($value, " \t\"'");
    }

    return $values;
}

function pdoFromEnv(string $path): PDO
{
    $env = parseEnvFile($path);
    $host = $env['DB_HOST'] ?? '127.0.0.1';
    $port = $env['DB_PORT'] ?? '3306';
    $database = $env['DB_DATABASE'] ?? '';
    $username = $env['DB_USERNAME'] ?? '';
    $password = $env['DB_PASSWORD'] ?? '';

    if ($database === '' || $username === '') {
        throw new RuntimeException('DB_DATABASE/DB_USERNAME missing from env');
    }

    return new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
    );
}

$options = getopt('', ['desk-env:', 'spoke-env:', 'site-code:', 'local-user-id:']);
$deskEnv = $options['desk-env'] ?? '';
$spokeEnv = $options['spoke-env'] ?? '';
$siteCode = $options['site-code'] ?? '';
$userIds = $options['local-user-id'] ?? [];

if ($deskEnv === '' || $spokeEnv === '' || $siteCode === '' || $userIds === []) {
    fwrite(STDERR, "Missing required arguments.\n");
    exit(1);
}

if (! is_array($userIds)) {
    $userIds = [$userIds];
}

$allowedUatPaths = [
    '/var/www/radium-desk-uat/.env',
    '/var/www/rdservice-in-uat/.env',
];

foreach ([$deskEnv, $spokeEnv] as $path) {
    if (! in_array($path, $allowedUatPaths, true)) {
        fwrite(STDERR, "Refusing non-UAT env path: {$path}\n");
        exit(1);
    }
}

$desk = pdoFromEnv($deskEnv);
$spoke = pdoFromEnv($spokeEnv);

$selectDesk = $desk->prepare(
    'SELECT id, local_user_id, central_wallet_id
     FROM central_wallet_account_links
     WHERE site_code = ? AND local_user_id = ? AND status = ?'
);
$updateSpoke = $spoke->prepare(
    'UPDATE central_wallet_account_links
     SET central_wallet_id = ?, desk_link_id = ?
     WHERE site_code = ? AND local_user_id = ? AND link_status = ?'
);

$results = [];
foreach ($userIds as $userId) {
    $selectDesk->execute([$siteCode, (int) $userId, 'active']);
    $deskRow = $selectDesk->fetch();

    if ($deskRow === false) {
        $results[] = ['local_user_id' => (int) $userId, 'status' => 'desk_missing'];
        continue;
    }

    $updateSpoke->execute([
        $deskRow['central_wallet_id'],
        (int) $deskRow['id'],
        $siteCode,
        (int) $userId,
        'active',
    ]);

    $results[] = [
        'local_user_id' => (int) $userId,
        'status' => 'aligned',
        'spoke_rows_updated' => $updateSpoke->rowCount(),
        'desk_link_id' => (int) $deskRow['id'],
    ];
}

echo json_encode(['read_only' => false, 'uat_only' => true, 'results' => $results], JSON_PRETTY_PRINT)."\n";
