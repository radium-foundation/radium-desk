<?php

namespace Tests\Feature\Cashfree;

use App\Enums\CashfreeMissedBatchHealDisposition;
use App\Models\AuditLog;
use App\Models\CashfreeWebhookLog;
use App\Models\Order;
use App\Services\Cashfree\CashfreeMissedWebhookHealService;
use App\Services\Cashfree\CashfreeWebhookProcessorService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\EnsuresCashfreeSystemUser;
use Tests\TestCase;

class CashfreeHealMissedBatchCommandTest extends TestCase
{
    use EnsuresCashfreeSystemUser;
    use RefreshDatabase;

    private int $systemUserId;

    /**
     * @var list<array{
     *     order_id: string,
     *     cf_payment_id: string,
     *     amount: int|float,
     *     serial: string,
     *     product: string,
     *     service: string,
     *     bank_reference: string,
     *     cf_order_id: string,
     *     payment_time: string
     * }>
     */
    private array $batch = [
        [
            'order_id' => 'RD3478381',
            'cf_payment_id' => '6182736145',
            'amount' => 499,
            'serial' => '2507I005575',
            'product' => 'MSO 1300 E3 RD L1',
            'service' => '1 Year Unlimited',
            'bank_reference' => '920921596836',
            'cf_order_id' => '6595996043',
            'payment_time' => '2026-08-07T11:47:15+05:30',
        ],
        [
            'order_id' => 'RD3478382',
            'cf_payment_id' => '6182736037',
            'amount' => 499,
            'serial' => '9903835',
            'product' => 'MFS110',
            'service' => '1 Year Unlimited',
            'bank_reference' => '621961704065',
            'cf_order_id' => '6595996093',
            'payment_time' => '2026-08-07T11:47:14+05:30',
        ],
        [
            'order_id' => 'RD3478386',
            'cf_payment_id' => '6182738898',
            'amount' => 979,
            'serial' => '7215377',
            'product' => 'MFS110',
            'service' => '3 Years Unlimited',
            'bank_reference' => '823021054622',
            'cf_order_id' => '6595998882',
            'payment_time' => '2026-08-07T11:47:48+05:30',
        ],
        [
            'order_id' => 'RD3478387',
            'cf_payment_id' => '6182742975',
            'amount' => 879,
            'serial' => 'FPSPL1141XX',
            'product' => 'MFS110',
            'service' => '3 Years Unlimited',
            'bank_reference' => '749330242196',
            'cf_order_id' => '6596001250',
            'payment_time' => '2026-08-07T11:48:40+05:30',
        ],
        [
            'order_id' => 'RD3478388',
            'cf_payment_id' => '6182741408',
            'amount' => 599,
            'serial' => '8641061',
            'product' => 'MFS110',
            'service' => '1 Year Unlimited',
            'bank_reference' => '127510395008',
            'cf_order_id' => '6596001477',
            'payment_time' => '2026-08-07T11:48:20+05:30',
        ],
        [
            'order_id' => 'RD3478391',
            'cf_payment_id' => '6182743843',
            'amount' => 499,
            'serial' => '9379808',
            'product' => 'MFS110',
            'service' => '1 Year Unlimited',
            'bank_reference' => '065188840362',
            'cf_order_id' => '6596003824',
            'payment_time' => '2026-08-07T11:48:51+05:30',
        ],
        [
            'order_id' => 'RD3478397',
            'cf_payment_id' => '6182746462',
            'amount' => 599,
            'serial' => '9705825',
            'product' => 'MFS110',
            'service' => '1 Year Unlimited',
            'bank_reference' => '127510473935',
            'cf_order_id' => '6596007996',
            'payment_time' => '2026-08-07T11:49:24+05:30',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $systemUser = $this->ensureCashfreeSystemUser();
        $this->systemUserId = $systemUser->id;
        $this->seed(SettingsSeeder::class);

        config([
            'cashfree.verify_signature' => false,
            'cashfree.api.app_id' => 'test-app-id',
            'cashfree.api.secret' => 'test-api-secret',
            'cashfree.api.base_url' => 'https://api.cashfree.test/pg',
            'cashfree.api.version' => '2026-01-01',
            'radiumbox.enabled' => false,
        ]);

        Queue::fake();
    }

    public function test_dry_run_is_default_and_performs_zero_writes(): void
    {
        $this->fakeCashfreeBatch();

        $ordersBefore = Order::query()->count();
        $logsBefore = CashfreeWebhookLog::query()->count();

        $this->artisan('cashfree:heal-missed-batch')
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('Would heal: 7')
            ->assertSuccessful();

        $this->assertSame($ordersBefore, Order::query()->count());
        $this->assertSame($logsBefore, CashfreeWebhookLog::query()->count());
        $this->assertSame(0, Order::query()->whereIn('order_id', $this->allowlistIds())->count());
    }

    public function test_dry_run_flag_shows_intended_payload_without_writes(): void
    {
        $this->fakeCashfreeBatch();

        $result = app(CashfreeMissedWebhookHealService::class)->heal(['RD3478382'], dryRun: true);

        $this->assertSame(CashfreeMissedBatchHealDisposition::WouldHeal, $result->orders[0]->disposition);
        $this->assertSame('6182736037', $result->orders[0]->cfPaymentId);
        $this->assertSame('PAYMENT_SUCCESS_WEBHOOK', $result->orders[0]->payload['type'] ?? null);
        $this->assertSame('6182736037', $result->orders[0]->payload['data']['payment']['cf_payment_id'] ?? null);
        $this->assertSame('9903835', $result->orders[0]->payload['data']['order']['order_tags']['serial_no'] ?? null);

        $this->artisan('cashfree:heal-missed-batch', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(0, CashfreeWebhookLog::query()->count());
        $this->assertSame(0, Order::query()->count());
    }

    public function test_rejects_non_allowlisted_order_ids(): void
    {
        $this->artisan('cashfree:heal-missed-batch', [
            '--dry-run' => true,
            '--order' => ['RD9999999'],
        ])
            ->expectsOutputToContain('not in the approved missed-webhook heal allowlist')
            ->assertFailed();

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, CashfreeWebhookLog::query()->count());
    }

    public function test_execute_and_dry_run_together_fails(): void
    {
        $this->artisan('cashfree:heal-missed-batch', [
            '--dry-run' => true,
            '--execute' => true,
        ])
            ->expectsOutputToContain('Pass either --dry-run or --execute')
            ->assertFailed();
    }

    public function test_blocks_non_paid_cashfree_order(): void
    {
        $row = $this->batch[0];
        Http::fake([
            'https://api.cashfree.test/pg/orders/RD3478381' => Http::response($this->orderEntity($row, status: 'ACTIVE'), 200),
            'https://api.cashfree.test/pg/orders/RD3478381/payments' => Http::response([$this->paymentEntity($row)], 200),
        ]);

        $result = app(CashfreeMissedWebhookHealService::class)->heal(['RD3478381'], dryRun: true);

        $this->assertSame(CashfreeMissedBatchHealDisposition::Blocked, $result->orders[0]->disposition);
        $this->assertSame('order_not_paid', $result->orders[0]->reason);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, CashfreeWebhookLog::query()->count());
    }

    public function test_blocks_missing_success_payment(): void
    {
        $row = $this->batch[0];
        $failedPayment = $this->paymentEntity($row);
        $failedPayment['payment_status'] = 'FAILED';

        Http::fake([
            'https://api.cashfree.test/pg/orders/RD3478381' => Http::response($this->orderEntity($row), 200),
            'https://api.cashfree.test/pg/orders/RD3478381/payments' => Http::response([$failedPayment], 200),
        ]);

        $result = app(CashfreeMissedWebhookHealService::class)->heal(['RD3478381'], dryRun: true);

        $this->assertSame(CashfreeMissedBatchHealDisposition::Blocked, $result->orders[0]->disposition);
        $this->assertSame('missing_success_payment', $result->orders[0]->reason);
        $this->assertSame(0, Order::query()->count());
    }

    public function test_blocks_missing_cf_payment_id(): void
    {
        $row = $this->batch[0];
        $payment = $this->paymentEntity($row);
        unset($payment['cf_payment_id']);

        Http::fake([
            'https://api.cashfree.test/pg/orders/RD3478381' => Http::response($this->orderEntity($row), 200),
            'https://api.cashfree.test/pg/orders/RD3478381/payments' => Http::response([$payment], 200),
        ]);

        $result = app(CashfreeMissedWebhookHealService::class)->heal(['RD3478381'], dryRun: true);

        $this->assertSame(CashfreeMissedBatchHealDisposition::Blocked, $result->orders[0]->disposition);
        $this->assertSame('missing_cf_payment_id', $result->orders[0]->reason);
        $this->assertSame(0, Order::query()->count());
    }

    public function test_skips_existing_desk_order(): void
    {
        $row = $this->batch[1];
        Order::query()->create([
            'order_id' => $row['order_id'],
            'cashfree_payment_id' => $row['cf_payment_id'],
            'serial_number' => $row['serial'],
            'status' => 'active',
            'created_by' => $this->systemUserId,
            'updated_by' => $this->systemUserId,
        ]);

        $this->fakeCashfreeForRows([$row]);

        $result = app(CashfreeMissedWebhookHealService::class)->heal([$row['order_id']], dryRun: false);

        $this->assertSame(CashfreeMissedBatchHealDisposition::Skipped, $result->orders[0]->disposition);
        $this->assertSame('desk_order_exists', $result->orders[0]->reason);
        $this->assertSame(1, Order::query()->where('order_id', $row['order_id'])->count());
        $this->assertSame(0, CashfreeWebhookLog::query()->count());
    }

    public function test_skips_existing_cf_payment_id_safely(): void
    {
        $row = $this->batch[2];
        Order::query()->create([
            'order_id' => $row['order_id'],
            'cashfree_payment_id' => $row['cf_payment_id'],
            'status' => 'active',
            'created_by' => $this->systemUserId,
            'updated_by' => $this->systemUserId,
        ]);

        $this->fakeCashfreeForRows([$row]);

        $result = app(CashfreeMissedWebhookHealService::class)->heal([$row['order_id']], dryRun: false);

        $this->assertSame(CashfreeMissedBatchHealDisposition::Skipped, $result->orders[0]->disposition);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(0, CashfreeWebhookLog::query()->count());
    }

    public function test_blocks_cf_payment_id_owned_by_other_order(): void
    {
        $row = $this->batch[2];
        Order::query()->create([
            'order_id' => 'RD-OTHER-OWNER',
            'cashfree_payment_id' => $row['cf_payment_id'],
            'status' => 'active',
            'created_by' => $this->systemUserId,
            'updated_by' => $this->systemUserId,
        ]);

        $this->fakeCashfreeForRows([$row]);

        $result = app(CashfreeMissedWebhookHealService::class)->heal([$row['order_id']], dryRun: false);

        $this->assertSame(CashfreeMissedBatchHealDisposition::Blocked, $result->orders[0]->disposition);
        $this->assertSame('cf_payment_id_owned_by_other_order', $result->orders[0]->reason);
        $this->assertSame(0, Order::query()->where('order_id', $row['order_id'])->count());
    }

    public function test_skips_existing_processed_webhook_safely(): void
    {
        $row = $this->batch[4];
        $payload = [
            'type' => 'PAYMENT_SUCCESS_WEBHOOK',
            'data' => [
                'order' => ['order_id' => $row['order_id']],
                'payment' => [
                    'cf_payment_id' => $row['cf_payment_id'],
                    'payment_status' => 'SUCCESS',
                ],
            ],
        ];

        CashfreeWebhookLog::query()->create([
            'cf_payment_id' => $row['cf_payment_id'],
            'request_headers' => [],
            'request_payload' => $payload,
            'raw_body' => json_encode($payload),
            'received_at' => now(),
            'processing_status' => CashfreeWebhookLog::STATUS_PROCESSED,
            'processed_at' => now(),
        ]);

        $this->fakeCashfreeForRows([$row]);

        $result = app(CashfreeMissedWebhookHealService::class)->heal([$row['order_id']], dryRun: false);

        $this->assertSame(CashfreeMissedBatchHealDisposition::Skipped, $result->orders[0]->disposition);
        $this->assertSame('processed_webhook_exists', $result->orders[0]->reason);
        $this->assertSame(1, CashfreeWebhookLog::query()->count());
        $this->assertSame(0, Order::query()->count());
    }

    public function test_execute_uses_processor_and_preserves_synthetic_marker(): void
    {
        $row = $this->batch[1];
        $this->fakeCashfreeForRows([$row]);

        $result = app(CashfreeMissedWebhookHealService::class)->heal([$row['order_id']], dryRun: false);

        $this->assertSame(CashfreeMissedBatchHealDisposition::Healed, $result->orders[0]->disposition);

        $log = CashfreeWebhookLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame(CashfreeWebhookLog::STATUS_PROCESSED, $log->processing_status);
        $this->assertSame(
            CashfreeMissedWebhookHealService::INGEST_SOURCE,
            $log->request_headers['X-Desk-Ingest-Source'][0] ?? null,
        );
        $this->assertSame(
            'aug7-2026-missed-webhook',
            $log->request_headers['X-Desk-Reconcile-Batch'][0] ?? null,
        );
        $this->assertSame(
            CashfreeMissedWebhookHealService::USER_AGENT,
            $log->user_agent,
        );

        $order = Order::query()->where('order_id', $row['order_id'])->first();
        $this->assertNotNull($order);
        $this->assertSame($row['cf_payment_id'], $order->cashfree_payment_id);
        $this->assertSame($row['serial'], $order->serial_number);
        $this->assertSame($row['product'], $order->product_name);
        $this->assertNotNull($log->incident_id);

        $this->assertTrue(
            AuditLog::query()
                ->where('event', CashfreeMissedWebhookHealService::AUDIT_RECOVERED)
                ->where('auditable_id', $log->id)
                ->exists(),
        );
    }

    public function test_execute_heals_all_seven_with_expected_serial_outcomes(): void
    {
        Order::query()->create([
            'order_id' => 'RD-FPSPL-OWNER',
            'serial_number' => 'FPSPL1141XX',
            'cashfree_payment_id' => 'existing-fpspl-owner',
            'status' => 'active',
            'created_by' => $this->systemUserId,
            'updated_by' => $this->systemUserId,
        ]);

        $this->fakeCashfreeBatch();

        $this->artisan('cashfree:heal-missed-batch', ['--execute' => true])
            ->expectsOutputToContain('Healed: 7')
            ->assertSuccessful();

        $this->assertSame(8, Order::query()->count());
        $this->assertSame(7, CashfreeWebhookLog::query()->count());

        foreach ($this->batch as $row) {
            $order = Order::query()->where('order_id', $row['order_id'])->first();
            $this->assertNotNull($order, $row['order_id'].' missing');
            $this->assertSame($row['cf_payment_id'], $order->cashfree_payment_id);

            if ($row['order_id'] === 'RD3478387') {
                $this->assertNull($order->serial_number);
                $this->assertSame('MFS110', $order->product_name);
                $invalidTagAudit = AuditLog::query()
                    ->where('event', CashfreeWebhookProcessorService::AUDIT_EVENT_INVALID_ORDER_TAG)
                    ->where('auditable_id', $order->id)
                    ->get()
                    ->first(fn (AuditLog $audit): bool => ($audit->new_values['reason'] ?? null) === 'serial_already_owned');
                $this->assertNotNull($invalidTagAudit);
            } else {
                $this->assertSame($row['serial'], $order->serial_number);
            }
        }
    }

    public function test_rd3478387_never_assigns_fpspl_placeholder(): void
    {
        Order::query()->create([
            'order_id' => 'RD-FPSPL-OWNER-2',
            'serial_number' => 'FPSPL1141XX',
            'cashfree_payment_id' => 'existing-fpspl-owner-2',
            'status' => 'active',
            'created_by' => $this->systemUserId,
            'updated_by' => $this->systemUserId,
        ]);

        $row = $this->batch[3];
        $this->fakeCashfreeForRows([$row]);

        $result = app(CashfreeMissedWebhookHealService::class)->heal(['RD3478387'], dryRun: false);

        $this->assertSame(CashfreeMissedBatchHealDisposition::Healed, $result->orders[0]->disposition);

        $order = Order::query()->where('order_id', 'RD3478387')->first();
        $this->assertNotNull($order);
        $this->assertNull($order->serial_number);
        $this->assertNotSame('FPSPL1141XX', $order->serial_number);
    }

    public function test_rerunning_execute_does_not_duplicate_orders(): void
    {
        $row = $this->batch[5];
        $this->fakeCashfreeForRows([$row]);

        $service = app(CashfreeMissedWebhookHealService::class);

        $first = $service->heal([$row['order_id']], dryRun: false);
        $second = $service->heal([$row['order_id']], dryRun: false);

        $this->assertSame(CashfreeMissedBatchHealDisposition::Healed, $first->orders[0]->disposition);
        $this->assertSame(CashfreeMissedBatchHealDisposition::Skipped, $second->orders[0]->disposition);
        $this->assertSame(1, Order::query()->where('order_id', $row['order_id'])->count());
        $this->assertSame(1, CashfreeWebhookLog::query()->where('cf_payment_id', $row['cf_payment_id'])->count());
    }

    public function test_artisan_execute_wires_to_service_for_single_allowlisted_order(): void
    {
        $row = $this->batch[1];
        $this->fakeCashfreeForRows([$row]);

        $this->artisan('cashfree:heal-missed-batch --execute --order='.$row['order_id'])
            ->expectsOutputToContain('Healed: 1')
            ->assertSuccessful();

        $this->assertSame(1, Order::query()->where('order_id', $row['order_id'])->count());
        $this->assertSame(1, CashfreeWebhookLog::query()->count());
    }

    public function test_concurrent_idempotent_service_calls_do_not_duplicate(): void
    {
        $row = $this->batch[6];
        $this->fakeCashfreeForRows([$row]);

        $service = app(CashfreeMissedWebhookHealService::class);

        $first = $service->heal([$row['order_id']], dryRun: false);
        $second = $service->heal([$row['order_id']], dryRun: false);

        $this->assertSame(CashfreeMissedBatchHealDisposition::Healed, $first->orders[0]->disposition);
        $this->assertSame(CashfreeMissedBatchHealDisposition::Skipped, $second->orders[0]->disposition);
        $this->assertSame(1, Order::query()->where('order_id', $row['order_id'])->count());
    }

    public function test_allowlist_contains_exactly_seven_approved_orders(): void
    {
        $this->assertSame($this->allowlistIds(), app(CashfreeMissedWebhookHealService::class)->allowlist());
    }

    /**
     * @return list<string>
     */
    private function allowlistIds(): array
    {
        return array_column($this->batch, 'order_id');
    }

    private function fakeCashfreeBatch(): void
    {
        $this->fakeCashfreeForRows($this->batch);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function fakeCashfreeForRows(array $rows): void
    {
        $fakes = [];

        foreach ($rows as $row) {
            $orderId = $row['order_id'];
            $fakes["https://api.cashfree.test/pg/orders/{$orderId}"] = Http::response($this->orderEntity($row), 200);
            $fakes["https://api.cashfree.test/pg/orders/{$orderId}/payments"] = Http::response([
                $this->paymentEntity($row),
            ], 200);
        }

        Http::fake($fakes);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function orderEntity(array $row, string $status = 'PAID'): array
    {
        return [
            'order_id' => $row['order_id'],
            'cf_order_id' => $row['cf_order_id'],
            'order_status' => $status,
            'order_amount' => $row['amount'],
            'order_currency' => 'INR',
            'customer_details' => [
                'customer_name' => 'Heal Customer '.$row['order_id'],
                'customer_email' => strtolower($row['order_id']).'@example.com',
                'customer_phone' => '9908734801',
            ],
            'order_tags' => [
                'product_name' => $row['product'],
                'serial_no' => $row['serial'],
                'rd_service_name' => $row['service'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function paymentEntity(array $row): array
    {
        return [
            'cf_payment_id' => $row['cf_payment_id'],
            'order_id' => $row['order_id'],
            'payment_status' => 'SUCCESS',
            'payment_amount' => $row['amount'],
            'payment_currency' => 'INR',
            'payment_time' => $row['payment_time'],
            'payment_group' => 'upi',
            'bank_reference' => $row['bank_reference'],
            'payment_gateway_details' => [
                'gateway_name' => 'CASHFREE',
                'gateway_order_id' => $row['cf_order_id'],
                'gateway_payment_id' => $row['cf_payment_id'],
            ],
        ];
    }
}
