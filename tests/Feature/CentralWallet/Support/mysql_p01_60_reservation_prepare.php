<?php

use App\CentralWallet\Application\CustomerIdentitySubjectHasher;
use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\CentralWallet\Support\P01_60ReservationMysqlGate;
use Tests\Feature\Refunds\Support\RefundCompletionMysqlPrepareLock;

require __DIR__.'/mysql_p01_60_reservation_bootstrap.php';

$options = getopt('', ['scenario::', 'suffix::']);
$scenario = strtolower((string) ($options['scenario'] ?? 'competing_reserve'));
$suffix = (string) ($options['suffix'] ?? bin2hex(random_bytes(4)));

$lockHandle = RefundCompletionMysqlPrepareLock::acquire();
register_shutdown_function(
    static function () use ($lockHandle): void {
        RefundCompletionMysqlPrepareLock::release($lockHandle);
    }
);

$integrationToken = 'p01-60-conc-'.substr($suffix, 0, 12);
$credentials = p01_60_reservation_mysql_credentials_from_env();
$app = p01_60_reservation_mysql_boot_app($credentials, $integrationToken);

$connected = DB::selectOne('select database() as d');
if (! is_object($connected) || ! P01_60ReservationMysqlGate::isAllowedDatabase((string) $connected->d)) {
    fwrite(STDERR, "Prepare refused: unexpected database.\n");
    exit(2);
}

if (Artisan::call('db:wipe', ['--force' => true, '--drop-views' => true]) !== 0) {
    fwrite(STDERR, Artisan::output());
    exit(2);
}
if (Artisan::call('migrate', ['--force' => true]) !== 0) {
    fwrite(STDERR, Artisan::output());
    exit(2);
}

$siteCode = 'radiumbox.com';
$localUserId = '880'.substr(preg_replace('/\D/', '', $suffix) ?: '01', 0, 3);
$email = 'p01-60-conc-'.$suffix.'@example.test';
$cwId = (string) Str::uuid();
$customerId = (string) Str::uuid();

DB::table('central_wallets')->insert([
    'id' => $cwId,
    'status' => 'active',
    'created_at' => now(),
    'updated_at' => now(),
]);

CentralCustomer::query()->create([
    'id' => $customerId,
    'central_wallet_id' => $cwId,
    'status' => 'active',
]);

CentralCustomerIdentityCredential::query()->create([
    'desk_customer_id' => $customerId,
    'credential_type' => CustomerIdentityCredentialType::VerifiedEmail,
    'provider' => 'desk_email',
    'subject_hash' => app(CustomerIdentitySubjectHasher::class)->hashVerifiedEmail($email),
    'verified_at' => now(),
]);

CentralWalletAccountLink::query()->create([
    'central_wallet_id' => $cwId,
    'desk_customer_id' => $customerId,
    'site_code' => $siteCode,
    'local_user_id' => $localUserId,
    'status' => AccountLinkStatus::Active,
    'verification_method' => 'canonical_account_identity',
    'created_by' => 'p01_60_concurrency_test',
    'linked_at' => now(),
]);

$ledgerBalance = $scenario === 'competing_reserve' ? '30.00' : '100.00';
p01_60_reservation_credit_balance($cwId, $ledgerBalance, $siteCode);

$spendAuthorization = [
    'site_code' => $siteCode,
    'local_user_id' => $localUserId,
    'email' => $email,
    'email_verified' => true,
];

$reservationId = null;
if ($scenario === 'concurrent_commit') {
    $create = p01_60_reservation_http_post($app, [
        'integration_token' => $integrationToken,
        'site_code' => $siteCode,
    ], '/api/central-wallet/v1/wallet-reservations', [
        'idempotency_key' => 'prepare-reserve-'.$suffix,
        'central_wallet_id' => $cwId,
        'amount' => '25.00',
        'business_reference' => 'order:CONC-'.$suffix,
        'spend_authorization' => $spendAuthorization,
    ]);

    if (($create['http_status'] ?? 0) !== 201) {
        fwrite(STDERR, 'Prepare failed to seed reservation: '.json_encode($create)."\n");
        exit(2);
    }

    $reservationId = is_array($create['body']) ? ($create['body']['reservation_id'] ?? null) : null;
    if (! is_string($reservationId) || $reservationId === '') {
        fwrite(STDERR, "Prepare missing reservation_id.\n");
        exit(2);
    }
}

$context = [
    'scenario' => $scenario,
    'suffix' => $suffix,
    'integration_token' => $integrationToken,
    'site_code' => $siteCode,
    'local_user_id' => $localUserId,
    'email' => $email,
    'central_wallet_id' => $cwId,
    'spend_authorization' => $spendAuthorization,
    'reserve_amount' => '20.00',
    'reservation_id' => $reservationId,
    'commit_idempotency_key' => 'commit-conc-'.$suffix,
];

fwrite(STDOUT, json_encode($context, JSON_THROW_ON_ERROR));
