<?php

namespace Tests\Feature\Cashfree;

use App\Enums\FinanceJournalSourceType;
use App\Infrastructure\Queue\QueueRouting;
use App\Listeners\Finance\PostOrderPaidJournal;
use App\Models\CashfreeWebhookLog;
use App\Models\FinanceJournal;
use App\Models\User;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CashfreeWebhookOrderPaidQueueIsolationTest extends TestCase
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
            'cashfree.verify_signature' => false,
            'radiumbox.enabled' => false,
            'radiumbox.payment_confirm.enabled' => true,
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.test',
            'order_lookup.spokes.radiumbox_com.token' => 'desk-token',
        ]);

        DB::table('jobs')->delete();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);

        User::factory()->create([
            'email' => 'superadmin@radium.local',
            'is_active' => true,
        ])->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        Http::fake([
            'https://radiumbox.test/api/integrations/v1/cashfree/confirm-payment' => Http::response([
                'status' => 'paid',
                'gateway_order_id' => 'RBP200',
            ], 200),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function successfulHardwarePayload(): array
    {
        return [
            'type' => 'PAYMENT_SUCCESS_WEBHOOK',
            'data' => [
                'order' => [
                    'order_id' => 'RBP200',
                    'order_amount' => 4998,
                    'order_currency' => 'INR',
                ],
                'payment' => [
                    'cf_payment_id' => '1453002800',
                    'payment_status' => 'SUCCESS',
                    'payment_amount' => 4998,
                    'payment_currency' => 'INR',
                    'payment_time' => '2022-12-15T12:20:29+05:30',
                    'payment_group' => 'upi',
                ],
                'customer_details' => [
                    'customer_name' => 'Jane Doe',
                    'customer_email' => 'test@gmail.com',
                    'customer_phone' => '9908734801',
                ],
                'payment_gateway_details' => [
                    'gateway_name' => 'CASHFREE',
                    'gateway_order_id' => 'RBP200',
                    'gateway_payment_id' => '1504280030',
                ],
            ],
        ];
    }

    private function listenerJobsOnCritical(string $listenerClass): int
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

    public function test_successful_webhook_queues_journal_work_without_running_it_before_http_response(): void
    {
        $response = $this->postJson('/api/webhooks/cashfree', $this->successfulHardwarePayload());

        $response->assertOk()->assertExactJson(['status' => 'ok']);

        $log = CashfreeWebhookLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame('processed', $log->processing_status);

        $this->assertGreaterThanOrEqual(
            1,
            $this->listenerJobsOnCritical(PostOrderPaidJournal::class),
            'PostOrderPaidJournal must be queued on critical',
        );

        $this->assertSame(
            0,
            FinanceJournal::query()->where('source_type', FinanceJournalSourceType::OrderPayment)->count(),
            'Journal must not post synchronously on the webhook request',
        );

        Http::assertNothingSent();
    }
}
