<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\CashfreeCentralCustomerBinder;
use App\CentralWallet\Application\CashfreeHistoricalIdentityRepairIdentityClass;
use App\CentralWallet\Application\CashfreeHistoricalIdentityRepairPlanner;
use App\CentralWallet\Application\CashfreeHistoricalIdentityRepairPopulationQuery;
use App\CentralWallet\Application\CashfreeHistoricalIdentityRepairRunner;
use App\CentralWallet\Application\CustomerIdentitySubjectHasher;
use App\CentralWallet\Application\LedgerService;
use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAuditEvent;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\Models\CashfreeWebhookLog;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashfreeHistoricalIdentityRepairTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'central_wallet.historical_identity_repair.deploy_cutoff_utc' => '2099-01-01 00:00:00',
            'central_wallet.historical_identity_repair.apply_enabled' => true,
            'central_wallet.historical_identity_repair.apply_confirm_token' => 'TEST_CONFIRM',
        ]);
    }

    private function seedHistoricalOrder(
        string $businessId,
        string $email,
        ?string $customerId = null,
        string $cfPaymentId = 'cf-hist-1',
    ): Order {
        $order = Order::query()->create([
            'order_id' => $businessId,
            'customer_email' => $email,
            'customer_name' => 'Hist Customer',
            'customer_phone' => '9000000000',
            'customer_id' => $customerId,
            'cashfree_payment_id' => $cfPaymentId,
            'payment_amount' => 499,
            'status' => 'active',
            'created_at' => Carbon::parse('2026-01-01 12:00:00', 'UTC'),
        ]);

        CashfreeWebhookLog::query()->create([
            'cf_payment_id' => $cfPaymentId,
            'processing_status' => CashfreeWebhookLog::STATUS_PROCESSED,
            'received_at' => now(),
            'request_payload' => [],
            'request_headers' => ['content-type' => 'application/json'],
            'raw_body' => '{}',
            'source_ip' => '127.0.0.1',
        ]);

        return $order;
    }

    public function test_dry_run_performs_zero_writes(): void
    {
        $this->seedHistoricalOrder('RD-H-1', 'dry-run@example.com', null, 'cf-dry-1');
        $customersBefore = CentralCustomer::query()->count();

        Artisan::call('cashfree:repair-historical-identity', [
            '--mode' => 'dry-run',
            '--order' => ['RD-H-1'],
        ]);

        $this->assertSame($customersBefore, CentralCustomer::query()->count());
        $this->assertNull(Order::query()->where('order_id', 'RD-H-1')->value('customer_id'));
    }

    public function test_command_requires_explicit_mode(): void
    {
        $exit = Artisan::call('cashfree:repair-historical-identity');
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--mode', Artisan::output());
    }

    public function test_apply_mode_rejected_from_cli_when_apply_disabled(): void
    {
        config(['central_wallet.historical_identity_repair.apply_enabled' => false]);
        $exit = Artisan::call('cashfree:repair-historical-identity', [
            '--mode' => 'apply',
            '--confirm' => 'TEST_CONFIRM',
            '--order' => ['RD-H-1'],
        ]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('disabled', Artisan::output());
    }

    public function test_apply_mode_rejected_from_cli_without_confirm_token(): void
    {
        $exit = Artisan::call('cashfree:repair-historical-identity', [
            '--mode' => 'apply',
            '--order' => ['RD-H-1'],
        ]);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--confirm', Artisan::output());
    }

    public function test_apply_mode_cli_binds_when_gated(): void
    {
        $this->seedHistoricalOrder('RD-H-CLI', 'cli-apply@example.com', null, 'cf-h-cli');
        $exit = Artisan::call('cashfree:repair-historical-identity', [
            '--mode' => 'apply',
            '--confirm' => 'TEST_CONFIRM',
            '--order' => ['RD-H-CLI'],
            '--run-id' => 'cli-run-1',
        ]);
        $this->assertSame(0, $exit);
        $this->assertNotNull(Order::query()->where('order_id', 'RD-H-CLI')->value('customer_id'));
    }

    public function test_apply_passes_uuid_correlation_id_to_wallet_audit_events(): void
    {
        $before = CentralWalletAuditEvent::query()->count();
        $this->seedHistoricalOrder('RD-H-UUID', 'uuid-corr@example.com', null, 'cf-h-uuid');

        app(CashfreeHistoricalIdentityRepairRunner::class)->runApply(
            'TEST_CONFIRM',
            null,
            ['RD-H-UUID'],
            null,
            'run-uuid',
        );

        $newEvents = CentralWalletAuditEvent::query()->orderByDesc('id')->limit(5)->get();
        $this->assertGreaterThan($before, $newEvents->count());
        foreach ($newEvents as $event) {
            $this->assertTrue(
                Str::isUuid((string) $event->correlation_id),
                'Expected UUID correlation_id on audit event '.$event->event_type,
            );
        }
    }

    public function test_existing_customer_binds_multiple_orders(): void
    {
        $email = 'existing-hist@example.com';
        $customerId = (string) Str::uuid();
        $walletId = (string) Str::uuid();
        CentralWallet::query()->create(['id' => $walletId, 'status' => 'active']);
        CentralCustomer::query()->create(['id' => $customerId, 'central_wallet_id' => $walletId, 'status' => 'active']);
        CentralCustomerIdentityCredential::query()->create([
            'desk_customer_id' => $customerId,
            'credential_type' => CustomerIdentityCredentialType::VerifiedEmail,
            'provider' => CashfreeCentralCustomerBinder::PROVIDER_DESK_EMAIL,
            'subject_hash' => app(CustomerIdentitySubjectHasher::class)->hashVerifiedEmail($email),
            'verified_at' => now(),
        ]);

        $this->seedHistoricalOrder('RD-H-2A', $email, null, 'cf-h-2a');
        $this->seedHistoricalOrder('RD-H-2B', $email, null, 'cf-h-2b');

        $runner = app(CashfreeHistoricalIdentityRepairRunner::class);
        $summary = $runner->runApply('TEST_CONFIRM', null, ['RD-H-2A', 'RD-H-2B'], null, 'test-run-2');

        $this->assertSame(2, $summary->plannedMutations);
        $this->assertSame($customerId, (string) Order::query()->where('order_id', 'RD-H-2A')->value('customer_id'));
        $this->assertSame($customerId, (string) Order::query()->where('order_id', 'RD-H-2B')->value('customer_id'));
        $this->assertSame(1, CentralCustomer::query()->count());
    }

    public function test_new_email_creates_one_customer_and_wallet_for_multiple_orders(): void
    {
        $email = 'new-cohort@example.com';
        $this->seedHistoricalOrder('RD-H-3A', $email, null, 'cf-h-3a');
        $this->seedHistoricalOrder('RD-H-3B', $email, null, 'cf-h-3b');

        app(CashfreeHistoricalIdentityRepairRunner::class)->runApply('TEST_CONFIRM', null, ['RD-H-3A', 'RD-H-3B'], null, 'test-run-3');

        $this->assertSame(1, CentralCustomer::query()->count());
        $this->assertSame(1, CentralWallet::query()->count());
        $customerId = (string) Order::query()->where('order_id', 'RD-H-3A')->value('customer_id');
        $this->assertSame($customerId, (string) Order::query()->where('order_id', 'RD-H-3B')->value('customer_id'));
        $this->assertSame(
            '0.00',
            app(LedgerService::class)->availableBalance((string) CentralCustomer::query()->value('central_wallet_id')),
        );
    }

    public function test_ambiguous_email_does_not_mutate(): void
    {
        $email = 'ambiguous@example.com';
        $hash = app(CustomerIdentitySubjectHasher::class)->hashVerifiedEmail($email);
        $providers = [
            CashfreeCentralCustomerBinder::PROVIDER_DESK_EMAIL,
            CashfreeCentralCustomerBinder::PROVIDER_CASHFREE_ORDER_EMAIL,
        ];
        foreach ([Str::uuid(), Str::uuid()] as $index => $cid) {
            $wid = (string) Str::uuid();
            CentralWallet::query()->create(['id' => $wid, 'status' => 'active']);
            CentralCustomer::query()->create(['id' => $cid, 'central_wallet_id' => $wid, 'status' => 'active']);
            CentralCustomerIdentityCredential::query()->create([
                'desk_customer_id' => $cid,
                'credential_type' => CustomerIdentityCredentialType::VerifiedEmail,
                'provider' => $providers[$index],
                'subject_hash' => $hash,
                'verified_at' => now(),
            ]);
        }

        $this->seedHistoricalOrder('RD-H-4', $email, null, 'cf-h-4');

        $query = app(CashfreeHistoricalIdentityRepairPopulationQuery::class)->eligibleOrdersQuery();
        app(CashfreeHistoricalIdentityRepairPopulationQuery::class)->applySelectionFilters($query, null, ['RD-H-4'], null);
        $plans = app(CashfreeHistoricalIdentityRepairPlanner::class)->buildPlans($query, null);

        $this->assertSame(CashfreeHistoricalIdentityRepairIdentityClass::Ambiguous, $plans[0]->identityClass);
        $this->assertNull(Order::query()->where('order_id', 'RD-H-4')->value('customer_id'));
    }

    public function test_invalid_email_skipped(): void
    {
        $this->seedHistoricalOrder('RD-H-5', 'not-an-email', null, 'cf-h-5');

        Artisan::call('cashfree:repair-historical-identity', [
            '--mode' => 'dry-run',
            '--order' => ['RD-H-5'],
        ]);

        $this->assertNull(Order::query()->where('order_id', 'RD-H-5')->value('customer_id'));
    }

    public function test_already_bound_order_unchanged_in_plan(): void
    {
        $customerId = (string) Str::uuid();
        $walletId = (string) Str::uuid();
        CentralWallet::query()->create(['id' => $walletId, 'status' => 'active']);
        CentralCustomer::query()->create(['id' => $customerId, 'central_wallet_id' => $walletId, 'status' => 'active']);

        $this->seedHistoricalOrder('RD-H-6', 'bound@example.com', $customerId, 'cf-h-6');

        $query = app(CashfreeHistoricalIdentityRepairPopulationQuery::class)->eligibleOrdersQuery();
        app(CashfreeHistoricalIdentityRepairPopulationQuery::class)->applySelectionFilters($query, null, ['RD-H-6'], null);
        $plans = app(CashfreeHistoricalIdentityRepairPlanner::class)->buildPlans($query, null);

        $this->assertSame(CashfreeHistoricalIdentityRepairIdentityClass::AlreadyBound, $plans[0]->identityClass);
    }

    public function test_retry_apply_is_idempotent_for_same_cohort(): void
    {
        $email = 'retry@example.com';
        $this->seedHistoricalOrder('RD-H-7', $email, null, 'cf-h-7');

        $runner = app(CashfreeHistoricalIdentityRepairRunner::class);
        $runner->runApply('TEST_CONFIRM', null, ['RD-H-7'], null, 'run-a');
        $runner->runApply('TEST_CONFIRM', null, ['RD-H-7'], null, 'run-b');

        $this->assertSame(1, CentralCustomer::query()->count());
    }

    public function test_rd16854_fixture_plan(): void
    {
        $order = $this->seedHistoricalOrder('RD16854', 'visheshp453@gmail.com', null, '6699863066');
        $user = User::factory()->create();
        RefundRequest::query()->create([
            'order_id' => $order->id,
            'requested_by' => $user->id,
            'reference_no' => 'REF-67392',
            'amount' => 499,
            'refund_amount' => 499,
            'reason' => 'test',
            'status' => 'closed',
            'approved_refund_method' => 'wallet',
            'execution_transaction_id' => '2663',
        ]);

        Artisan::call('cashfree:repair-historical-identity', [
            '--mode' => 'dry-run',
            '--order' => ['RD16854'],
        ]);

        $output = Artisan::output();
        $this->assertStringContainsString('RD16854', $output);
        $this->assertStringContainsString('NEW_CUSTOMER_REQUIRED', $output);
        $this->assertStringContainsString('REFUND_EXISTS_SPOKE_WALLET', $output);
    }

    public function test_apply_does_not_mutate_refund_requests(): void
    {
        $order = $this->seedHistoricalOrder('RD-H-8', 'no-refund-mut@example.com', null, 'cf-h-8');
        $user = User::factory()->create();
        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'requested_by' => $user->id,
            'reference_no' => 'REF-HIST-8',
            'amount' => 100,
            'refund_amount' => 100,
            'reason' => 'test',
            'status' => 'closed',
            'approved_refund_method' => 'wallet',
            'execution_transaction_id' => '999',
        ]);

        app(CashfreeHistoricalIdentityRepairRunner::class)->runApply('TEST_CONFIRM', null, ['RD-H-8'], null, 'run-8');

        $refund->refresh();
        $this->assertSame('999', (string) $refund->execution_transaction_id);
        $this->assertSame('closed', $refund->status->value);
    }

    public function test_apply_does_not_create_ledger_entries(): void
    {
        $before = CentralWalletLedgerEntry::query()->count();
        $this->seedHistoricalOrder('RD-H-9', 'ledger-safe@example.com', null, 'cf-h-9');
        app(CashfreeHistoricalIdentityRepairRunner::class)->runApply('TEST_CONFIRM', null, ['RD-H-9'], null, 'run-9');
        $this->assertSame($before, CentralWalletLedgerEntry::query()->count());
    }

    public function test_batch_limit_does_not_split_email_cohort(): void
    {
        $email = 'batch@example.com';
        $this->seedHistoricalOrder('RD-H-10A', $email, null, 'cf-h-10a');
        $this->seedHistoricalOrder('RD-H-10B', $email, null, 'cf-h-10b');

        $query = app(CashfreeHistoricalIdentityRepairPopulationQuery::class)->eligibleOrdersQuery();
        app(CashfreeHistoricalIdentityRepairPopulationQuery::class)->applySelectionFilters($query, null, null, $email);
        $plans = app(CashfreeHistoricalIdentityRepairPlanner::class)->buildPlans($query, 1);

        $this->assertCount(1, $plans);
        $this->assertSame(2, $plans[0]->orderCount());
    }

    public function test_existing_wallet_balance_unchanged_after_bind(): void
    {
        $email = 'balance@example.com';
        $customerId = (string) Str::uuid();
        $walletId = (string) Str::uuid();
        CentralWallet::query()->create(['id' => $walletId, 'status' => 'active']);
        CentralCustomer::query()->create(['id' => $customerId, 'central_wallet_id' => $walletId, 'status' => 'active']);
        CentralCustomerIdentityCredential::query()->create([
            'desk_customer_id' => $customerId,
            'credential_type' => CustomerIdentityCredentialType::VerifiedEmail,
            'provider' => 'desk_email',
            'subject_hash' => app(CustomerIdentitySubjectHasher::class)->hashVerifiedEmail($email),
            'verified_at' => now(),
        ]);

        CentralWalletLedgerEntry::query()->create([
            'central_wallet_id' => $walletId,
            'entry_type' => 'credit',
            'amount' => '10.00',
            'status' => 'posted',
            'source_system' => 'test',
            'correlation_id' => (string) Str::uuid(),
            'posted_at' => now(),
        ]);

        $this->seedHistoricalOrder('RD-H-11', $email, null, 'cf-h-11');
        app(CashfreeHistoricalIdentityRepairRunner::class)->runApply('TEST_CONFIRM', null, ['RD-H-11'], null, 'run-11');

        $this->assertSame('10.00', app(LedgerService::class)->availableBalance($walletId));
    }
}
