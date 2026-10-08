<?php

declare(strict_types=1);

/**
 * READ-ONLY Central Wallet account-link reconciliation audit.
 *
 * Usage (on host with DB access):
 *   php tools/audit/central-wallet-account-link-reconciliation.php \
 *     --desk-env=/var/www/radium-desk/.env \
 *     --spoke-env=/var/www/rdservice.in/.env \
 *     --site-code=rdservice.in
 *
 * Performs SELECT queries only. No INSERT/UPDATE/DELETE.
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

function pdoFromEnv(array $env): PDO
{
    $host = $env['DB_HOST'] ?? '127.0.0.1';
    $port = $env['DB_PORT'] ?? '3306';
    $database = $env['DB_DATABASE'] ?? '';
    $username = $env['DB_USERNAME'] ?? '';
    $password = $env['DB_PASSWORD'] ?? '';

    if ($database === '' || $username === '') {
        throw new RuntimeException('DB_DATABASE/DB_USERNAME missing from env');
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function maskCwid(?string $cwid): string
{
    $cwid = trim((string) $cwid);
    if ($cwid === '') {
        return '(empty)';
    }

    return substr($cwid, 0, 8).'…';
}

function fetchDeskLinks(PDO $pdo, ?string $siteCode = null): array
{
    $sql = 'SELECT id, central_wallet_id, desk_customer_id, site_code, local_user_id, status, verification_method, linked_at, created_at, metadata
            FROM central_wallet_account_links';
    $params = [];
    if ($siteCode !== null) {
        $sql .= ' WHERE site_code = ?';
        $params[] = $siteCode;
    }
    $sql .= ' ORDER BY id';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function tableColumns(PDO $pdo, string $table): array
{
    $stmt = $pdo->query("SHOW COLUMNS FROM {$table}");
    $columns = [];
    foreach ($stmt->fetchAll() as $row) {
        $columns[] = (string) $row['Field'];
    }

    return $columns;
}

function fetchSpokeLinks(PDO $pdo, string $siteCode): array
{
    $columns = tableColumns($pdo, 'central_wallet_account_links');
    $select = array_values(array_intersect(
        [
            'id', 'central_wallet_id', 'desk_customer_id', 'local_user_id', 'site_code',
            'link_status', 'verification_method', 'linked_at', 'desk_link_id',
            'customer_display_ref', 'owner_gate_reference', 'created_at',
        ],
        $columns,
    ));

    $sql = 'SELECT '.implode(', ', $select).' FROM central_wallet_account_links WHERE site_code = ? ORDER BY id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$siteCode]);

    return $stmt->fetchAll();
}

function isTrustedVerification(?string $method): bool
{
    $method = trim((string) $method);

    return in_array($method, ['m2_whatsapp_otp', 'owner_identity_gate', 'desk_ceremony_complete'], true);
}

function reconcileSite(PDO $desk, PDO $spoke, string $siteCode): array
{
    $deskAll = fetchDeskLinks($desk, $siteCode);
    $deskActive = array_values(array_filter($deskAll, fn (array $r): bool => ($r['status'] ?? '') === 'active'));
    $deskInactive = array_values(array_filter($deskAll, fn (array $r): bool => ($r['status'] ?? '') !== 'active'));

    $spokeAll = fetchSpokeLinks($spoke, $siteCode);
    $spokeActive = array_values(array_filter($spokeAll, fn (array $r): bool => ($r['link_status'] ?? '') === 'active'));

    $deskByUser = [];
    foreach ($deskActive as $row) {
        $deskByUser[(string) $row['local_user_id']][] = $row;
    }

    $spokeByUser = [];
    foreach ($spokeActive as $row) {
        $spokeByUser[(string) $row['local_user_id']][] = $row;
    }

    $counts = [
        'match' => 0,
        'desk_missing_on_spoke' => 0,
        'spoke_only' => 0,
        'wallet_id_mismatch' => 0,
        'identity_mismatch' => 0,
        'duplicate_spoke_link' => 0,
        'duplicate_desk_link' => 0,
        'verification_method_mismatch' => 0,
        'status_mismatch' => 0,
        'migration_cohort_mismatch' => 0,
    ];

    $samples = [];

    foreach ($deskByUser as $userId => $deskRows) {
        $userKey = (string) $userId;
        if (count($deskRows) > 1) {
            $counts['duplicate_desk_link']++;
            $samples[] = sample('duplicate_desk_link', $userKey, $deskRows[0], null);
        }
    }

    foreach ($spokeByUser as $userId => $spokeRows) {
        $userKey = (string) $userId;
        if (count($spokeRows) > 1) {
            $counts['duplicate_spoke_link']++;
            $samples[] = sample('duplicate_spoke_link', $userKey, null, $spokeRows[0]);
        }
    }

    foreach ($deskByUser as $userId => $deskRows) {
        $userKey = (string) $userId;
        $desk = $deskRows[0];
        $spokeRows = $spokeByUser[$userKey] ?? [];

        if ($spokeRows === []) {
            $counts['desk_missing_on_spoke']++;
            if (count($samples) < 20) {
                $samples[] = sample('desk_missing_on_spoke', $userKey, $desk, null);
            }

            continue;
        }

        $spoke = $spokeRows[0];
        if ($desk['central_wallet_id'] !== $spoke['central_wallet_id']) {
            $counts['wallet_id_mismatch']++;
            $samples[] = sample('wallet_id_mismatch', $userKey, $desk, $spoke);

            continue;
        }

        $deskLinkId = (int) $desk['id'];
        $spokeDeskLinkId = $spoke['desk_link_id'] !== null ? (int) $spoke['desk_link_id'] : null;
        if ($spokeDeskLinkId !== null && $spokeDeskLinkId !== $deskLinkId) {
            $counts['identity_mismatch']++;
            $samples[] = sample('identity_mismatch', $userKey, $desk, $spoke);
        }

        $deskVerify = trim((string) ($desk['verification_method'] ?? ''));
        $spokeVerify = trim((string) ($spoke['verification_method'] ?? ''));
        if ($deskVerify !== $spokeVerify) {
            $counts['verification_method_mismatch']++;
            if (count($samples) < 25) {
                $samples[] = sample('verification_method_mismatch', $userKey, $desk, $spoke);
            }
        }

        $counts['match']++;
    }

    foreach ($spokeByUser as $userId => $spokeRows) {
        $userKey = (string) $userId;
        if (! isset($deskByUser[$userKey])) {
            $counts['spoke_only']++;
            if (count($samples) < 30) {
                $samples[] = sample('spoke_only', $userKey, null, $spokeRows[0]);
            }
        }
    }

    return [
        'site_code' => $siteCode,
        'desk_active' => count($deskActive),
        'desk_inactive' => count($deskInactive),
        'spoke_local_active' => count($spokeActive),
        'spoke_local_total' => count($spokeAll),
        'counts' => $counts,
        'samples' => $samples,
    ];
}

function sample(string $classification, string $userId, ?array $desk, ?array $spoke): array
{
    return [
        'classification' => $classification,
        'local_user_id' => $userId,
        'desk' => $desk === null ? null : [
            'link_id' => (int) $desk['id'],
            'cwid' => maskCwid($desk['central_wallet_id'] ?? null),
            'verification_method' => $desk['verification_method'] ?? null,
            'desk_customer_id' => isset($desk['desk_customer_id']) ? maskCwid($desk['desk_customer_id']) : null,
        ],
        'spoke' => $spoke === null ? null : [
            'link_id' => (int) $spoke['id'],
            'cwid' => maskCwid($spoke['central_wallet_id'] ?? null),
            'desk_link_id' => $spoke['desk_link_id'] ?? null,
            'verification_method' => $spoke['verification_method'] ?? null,
            'desk_customer_id' => isset($spoke['desk_customer_id']) ? maskCwid($spoke['desk_customer_id']) : null,
        ],
    ];
}

function summarizeDesk(PDO $desk): array
{
    $all = fetchDeskLinks($desk);
    $active = array_filter($all, fn (array $r): bool => ($r['status'] ?? '') === 'active');
    $inactive = array_filter($all, fn (array $r): bool => ($r['status'] ?? '') !== 'active');

    $bySite = [];
    foreach ($active as $row) {
        $site = (string) $row['site_code'];
        $bySite[$site] = ($bySite[$site] ?? 0) + 1;
    }

    $dupUsers = [];
    $grouped = [];
    foreach ($active as $row) {
        $key = $row['site_code'].'|'.$row['local_user_id'];
        $grouped[$key][] = $row;
    }
    foreach ($grouped as $rows) {
        if (count($rows) > 1) {
            $dupUsers[] = [
                'site_code' => $rows[0]['site_code'],
                'local_user_id' => $rows[0]['local_user_id'],
                'count' => count($rows),
            ];
        }
    }

    $inactiveByStatus = [];
    foreach ($inactive as $row) {
        $status = (string) ($row['status'] ?? 'unknown');
        $inactiveByStatus[$status] = ($inactiveByStatus[$status] ?? 0) + 1;
    }

    return [
        'total' => count($all),
        'active' => count($active),
        'inactive' => count($inactive),
        'inactive_by_status' => $inactiveByStatus,
        'active_by_site' => $bySite,
        'duplicate_active_user_candidates' => $dupUsers,
    ];
}

$options = getopt('', ['desk-env:', 'spoke-env:', 'site-code:', 'json']);
$deskEnvPath = $options['desk-env'] ?? null;
$spokeEnvPath = $options['spoke-env'] ?? null;
$siteCode = $options['site-code'] ?? null;
$asJson = array_key_exists('json', $options);

if ($deskEnvPath === null || $spokeEnvPath === null || $siteCode === null) {
    fwrite(STDERR, "Usage: php central-wallet-account-link-reconciliation.php --desk-env=PATH --spoke-env=PATH --site-code=SITE [--json]\n");
    exit(1);
}

$deskPdo = pdoFromEnv(parseEnvFile($deskEnvPath));
$spokePdo = pdoFromEnv(parseEnvFile($spokeEnvPath));

$report = [
    'generated_at' => gmdate('c'),
    'read_only' => true,
    'desk_summary' => summarizeDesk($deskPdo),
    'site_reconciliation' => reconcileSite($deskPdo, $spokePdo, $siteCode),
];

if ($asJson) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
} else {
    print_r($report);
}
