<?php

namespace Tests\Feature\Cashfree;

use App\Enums\FinanceJournalSourceType;
use App\Enums\OrderStatus;
use App\Events\Finance\OrderPaid;
use App\Infrastructure\Queue\QueueRouting;
use App\Listeners\Finance\PostOrderPaidJournal;
use App\Listeners\RadiumBox\ConfirmRadiumBoxPaymentOnOrderPaid;
use App\Models\FinanceJournal;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Real database-queue persistence for OrderPaid queued listeners (no Queue::fake()).
 */
class OrderPaidQueuedListenerLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('jobs')) {
            RefreshDatabaseState::$migrated = false;
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());
            RefreshDatabaseState::$migrated = true;
        }

        $connectionName = (string) config('database.default');
        $transactionsManager = new DatabaseTransactionsManager;
        $this->app->instance('db.transactions', $transactionsManager);
        DB::connection($connectionName)->setTransactionManager($transactionsManager);

        config([
            'queue.default' => 'database',
            'queue.connections.database.driver' => 'database',
            'queue.connections.database.table' => 'jobs',
            'queue.connections.database.queue' => 'default',
            'queue.connections.database.retry_after' => 90,
            'queue.connections.database.after_commit' => false,
        ]);

        if (Schema::hasTable('jobs')) {
            DB::table('jobs')->delete();
        }

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
    }

    private function criticalJobs(): int
    {
        return (int) DB::table('jobs')->where('queue', QueueRouting::critical())->count();
    }

    private function listenerJobs(string $listenerClass): int
    {
        $count = 0;

        foreach (DB::table('jobs')->where('queue', QueueRouting::critical())->get() as $row) {
            $payload = json_decode((string) $row->payload, true);
            if (! is_array($payload)) {
                continue;
            }

            $command = unserialize((string) ($payload['data']['command'] ?? ''));
            if ($command instanceof CallQueuedListener && $command->class === $listenerClass) {
                $count++;
            }
        }

        return $count;
    }

    private function drainCriticalQueue(): void
    {
        $safety = 0;

        while ($this->criticalJobs() > 0 && $safety < 20) {
            $safety++;
            Artisan::call('queue:work', [
                'connection' => 'database',
                '--queue' => QueueRouting::critical(),
                '--once' => true,
                '--stop-when-empty' => true,
            ]);
        }
    }

    private function paidOrder(): Order
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return Order::query()->create([
            'order_id' => 'RD'.random_int(10000, 99999),
            'serial_number' => 'SN-'.uniqid(),
            'product_name' => 'Device',
            'device_model' => 'Model',
            'status' => OrderStatus::Active,
            'payment_amount' => 250.00,
            'payment_date' => now(),
            'cashfree_payment_id' => 'cf_'.uniqid(),
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);
    }

    public function test_uncommitted_order_paid_dispatch_leaves_zero_critical_listener_jobs(): void
    {
        $order = $this->paidOrder();

        DB::beginTransaction();

        OrderPaid::dispatch($order);

        $this->assertSame(0, $this->criticalJobs());

        DB::rollBack();

        $this->assertSame(0, $this->criticalJobs());
    }

    public function test_commit_queues_post_order_paid_journal_on_critical_queue(): void
    {
        $order = $this->paidOrder();

        DB::beginTransaction();
        OrderPaid::dispatch($order);
        $this->assertSame(0, $this->criticalJobs());
        DB::commit();

        $this->assertGreaterThanOrEqual(1, $this->listenerJobs(PostOrderPaidJournal::class));
        $this->assertSame(0, FinanceJournal::query()->where('source_type', FinanceJournalSourceType::OrderPayment)->count());

        $this->drainCriticalQueue();

        $this->assertSame(
            1,
            FinanceJournal::query()
                ->where('source_type', FinanceJournalSourceType::OrderPayment)
                ->where('idempotency_key', 'order_payment:'.$order->id)
                ->count(),
        );
    }

    public function test_duplicate_webhook_simulation_produces_single_journal_after_worker(): void
    {
        $order = $this->paidOrder();

        DB::transaction(fn () => OrderPaid::dispatch($order));
        DB::transaction(fn () => OrderPaid::dispatch($order->fresh()));

        $this->assertGreaterThanOrEqual(2, $this->listenerJobs(PostOrderPaidJournal::class));

        $this->drainCriticalQueue();

        $this->assertSame(
            1,
            FinanceJournal::query()
                ->where('source_type', FinanceJournalSourceType::OrderPayment)
                ->where('idempotency_key', 'order_payment:'.$order->id)
                ->count(),
        );
    }

    public function test_box_confirm_listener_respects_disabled_flag_when_worker_runs(): void
    {
        config(['radiumbox.payment_confirm.enabled' => false]);

        Http::fake();

        $order = $this->paidOrder();
        $order->forceFill(['order_id' => 'RBP77'])->save();

        DB::transaction(fn () => OrderPaid::dispatch($order->fresh()));

        $this->drainCriticalQueue();

        Http::assertNothingSent();
    }

    public function test_box_confirm_runs_on_worker_when_enabled(): void
    {
        config([
            'radiumbox.payment_confirm.enabled' => true,
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.test',
            'order_lookup.spokes.radiumbox_com.token' => 'desk-token',
        ]);

        Http::fake([
            'https://radiumbox.test/api/integrations/v1/cashfree/confirm-payment' => Http::response([
                'status' => 'paid',
                'gateway_order_id' => 'RBP88',
            ], 200),
        ]);

        $admin = User::factory()->create(['is_active' => true]);
        $order = Order::query()->create([
            'order_id' => 'RBP88',
            'cashfree_payment_id' => 'pay_88',
            'gateway_order_id' => 'RBP88',
            'payment_amount' => 100,
            'payment_method' => 'cashfree',
            'payment_date' => now(),
            'status' => OrderStatus::Active,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        DB::transaction(fn () => OrderPaid::dispatch($order));

        $this->assertGreaterThanOrEqual(1, $this->listenerJobs(ConfirmRadiumBoxPaymentOnOrderPaid::class));

        $this->drainCriticalQueue();

        Http::assertSent(fn ($request): bool => $request->url() === 'https://radiumbox.test/api/integrations/v1/cashfree/confirm-payment');
    }
}
