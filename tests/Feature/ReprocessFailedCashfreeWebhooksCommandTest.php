<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OrderStatus;
use App\Models\CashfreeWebhookLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReprocessFailedCashfreeWebhooksCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cashfree.verify_signature' => false]);

        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create([
            'email' => 'superadmin@radium.local',
            'is_active' => true,
        ]);
        $admin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->seed(SettingsSeeder::class);

        config(['radiumbox.enabled' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    private function successfulPayload(string $cfPaymentId = '1453002795', string $orderId = 'order_OFR_2'): array
    {
        return [
            'type' => 'PAYMENT_SUCCESS_WEBHOOK',
            'event_time' => '2023-08-01T11:16:10+05:30',
            'data' => [
                'order' => [
                    'order_id' => $orderId,
                    'order_amount' => 2,
                    'order_currency' => 'INR',
                ],
                'payment' => [
                    'cf_payment_id' => $cfPaymentId,
                    'payment_status' => 'SUCCESS',
                    'payment_amount' => 1,
                    'payment_currency' => 'INR',
                    'payment_time' => '2022-12-15T12:20:29+05:30',
                    'payment_group' => 'upi',
                    'bank_reference' => '234928698581',
                ],
                'customer_details' => [
                    'customer_name' => 'Jane Doe',
                    'customer_email' => 'test@gmail.com',
                    'customer_phone' => '9908734801',
                ],
                'payment_gateway_details' => [
                    'gateway_name' => 'CASHFREE',
                    'gateway_order_id' => '1634766330',
                    'gateway_payment_id' => '1504280029',
                ],
            ],
        ];
    }

    private function createFailedLog(array $overrides = []): CashfreeWebhookLog
    {
        $payload = $overrides['request_payload'] ?? $this->successfulPayload();

        return CashfreeWebhookLog::query()->create(array_merge([
            'webhook_version' => '2023-08-01',
            'cf_payment_id' => $payload['data']['payment']['cf_payment_id'] ?? null,
            'request_headers' => ['content-type' => ['application/json']],
            'request_payload' => $payload,
            'raw_body' => json_encode($payload),
            'received_at' => now(),
            'source_ip' => '127.0.0.1',
            'user_agent' => 'Cashfree-Webhook/1.0',
            'processing_status' => CashfreeWebhookLog::STATUS_FAILED,
            'processing_error' => 'Call to undefined method resolveAutomationActor()',
            'processed_at' => now(),
        ], $overrides));
    }

    public function test_command_is_registered(): void
    {
        $this->artisan('cashfree:reprocess-failed --help')
            ->assertSuccessful();
    }

    public function test_dry_run_reports_candidates_without_making_changes(): void
    {
        $log = $this->createFailedLog([
            'received_at' => Carbon::parse('2026-06-01 10:00:00'),
        ]);

        $this->artisan('cashfree:reprocess-failed --dry-run')
            ->assertSuccessful()
            ->expectsOutputToContain('Dry run: 1 webhook log(s) would be reprocessed.')
            ->expectsOutputToContain(sprintf('Log #%d (would recover)', $log->id))
            ->expectsOutputToContain('Total failed logs found: 1')
            ->expectsOutputToContain('Would recover: 1')
            ->expectsOutputToContain('Skipped (already exists): 0')
            ->expectsOutputToContain('Still failed: 0')
            ->expectsOutputToContain('Execution time:');

        $this->assertSame(CashfreeWebhookLog::STATUS_FAILED, $log->fresh()->processing_status);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Incident::query()->count());
    }

    public function test_successful_replay_creates_order_and_marks_log_processed(): void
    {
        $log = $this->createFailedLog();

        $this->artisan('cashfree:reprocess-failed')
            ->assertSuccessful()
            ->expectsOutputToContain('Total failed logs found: 1')
            ->expectsOutputToContain('Successfully recovered: 1')
            ->expectsOutputToContain('Skipped (already exists): 0')
            ->expectsOutputToContain('Still failed: 0');

        $log = $log->fresh();
        $this->assertSame(CashfreeWebhookLog::STATUS_PROCESSED, $log->processing_status);
        $this->assertNull($log->processing_error);
        $this->assertNotNull($log->incident_id);

        $order = Order::query()->where('cashfree_payment_id', '1453002795')->first();
        $this->assertNotNull($order);
        $this->assertSame('order_OFR_2', $order->order_id);

        $incident = Incident::query()->find($log->incident_id);
        $this->assertNotNull($incident);
        $this->assertSame(IncidentStatus::AwaitingProductDetails, $incident->status);
        $this->assertSame(IncidentSource::Cashfree, $incident->source);
    }

    public function test_idempotent_replay_skips_existing_order(): void
    {
        $systemUser = User::query()->where('email', 'superadmin@radium.local')->firstOrFail();

        $existingOrder = Order::query()->create([
            'order_id' => 'order_OFR_2',
            'customer_name' => 'Jane Doe',
            'customer_email' => 'test@gmail.com',
            'customer_phone' => '9908734801',
            'cashfree_payment_id' => '1453002795',
            'status' => OrderStatus::Active,
            'created_by' => $systemUser->id,
            'updated_by' => $systemUser->id,
        ]);

        $existingIncident = Incident::query()->create([
            'order_id' => $existingOrder->id,
            'reference_no' => 'SC-EXISTING',
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'Existing payment',
            'description' => 'Already created.',
            'status' => IncidentStatus::AwaitingProductDetails,
            'created_by' => $systemUser->id,
            'updated_by' => $systemUser->id,
        ]);

        $log = $this->createFailedLog();

        $this->artisan('cashfree:reprocess-failed')
            ->assertSuccessful()
            ->expectsOutputToContain('Successfully recovered: 0')
            ->expectsOutputToContain('Skipped (already exists): 1')
            ->expectsOutputToContain('Still failed: 0');

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, Incident::query()->count());

        $log = $log->fresh();
        $this->assertSame(CashfreeWebhookLog::STATUS_PROCESSED, $log->processing_status);
        $this->assertSame($existingIncident->id, $log->incident_id);
    }

    public function test_invalid_log_id_returns_failure(): void
    {
        $this->artisan('cashfree:reprocess-failed --log=99999')
            ->assertFailed()
            ->expectsOutputToContain('Webhook log #99999 was not found.');
    }

    public function test_single_log_option_replays_one_webhook(): void
    {
        $log = $this->createFailedLog();

        $this->artisan('cashfree:reprocess-failed --log='.$log->id)
            ->assertSuccessful()
            ->expectsOutputToContain('Successfully recovered: 1');

        $this->assertSame(CashfreeWebhookLog::STATUS_PROCESSED, $log->fresh()->processing_status);
    }

    public function test_continues_processing_when_one_replay_fails(): void
    {
        $recoverableFirst = $this->createFailedLog([
            'received_at' => Carbon::parse('2026-06-01 10:00:00'),
            'request_payload' => $this->successfulPayload('1111111111', 'order-recover-1'),
            'cf_payment_id' => '1111111111',
        ]);

        $payloadMissingPaymentId = $this->successfulPayload('2222222222', 'order-recover-2');
        unset($payloadMissingPaymentId['data']['payment']['cf_payment_id']);

        $stillFailing = $this->createFailedLog([
            'received_at' => Carbon::parse('2026-06-01 11:00:00'),
            'request_payload' => $payloadMissingPaymentId,
            'cf_payment_id' => null,
            'processing_error' => 'resolveAutomationActor() on null',
        ]);

        $recoverableSecond = $this->createFailedLog([
            'received_at' => Carbon::parse('2026-06-01 12:00:00'),
            'request_payload' => $this->successfulPayload('3333333333', 'order-recover-3'),
            'cf_payment_id' => '3333333333',
        ]);

        $this->artisan('cashfree:reprocess-failed')
            ->assertSuccessful()
            ->expectsOutputToContain('Total failed logs found: 3')
            ->expectsOutputToContain('Successfully recovered: 2')
            ->expectsOutputToContain('Skipped (already exists): 0')
            ->expectsOutputToContain('Still failed: 1');

        $this->assertSame(CashfreeWebhookLog::STATUS_PROCESSED, $recoverableFirst->fresh()->processing_status);
        $this->assertSame(CashfreeWebhookLog::STATUS_FAILED, $stillFailing->fresh()->processing_status);
        $this->assertSame(CashfreeWebhookLog::STATUS_PROCESSED, $recoverableSecond->fresh()->processing_status);
        $this->assertSame(2, Order::query()->count());
    }

    public function test_only_logs_with_resolve_automation_actor_errors_are_selected(): void
    {
        CashfreeWebhookLog::query()->create([
            'webhook_version' => '2023-08-01',
            'request_headers' => [],
            'request_payload' => $this->successfulPayload('4444444444', 'order-other-failure'),
            'raw_body' => '{}',
            'received_at' => now(),
            'source_ip' => '127.0.0.1',
            'user_agent' => 'test',
            'processing_status' => CashfreeWebhookLog::STATUS_FAILED,
            'processing_error' => 'Cashfree webhook payload is missing cf_payment_id.',
            'processed_at' => now(),
        ]);

        $this->createFailedLog([
            'request_payload' => $this->successfulPayload('5555555555', 'order-target'),
            'cf_payment_id' => '5555555555',
        ]);

        $this->artisan('cashfree:reprocess-failed --dry-run')
            ->assertSuccessful()
            ->expectsOutputToContain('Dry run: 1 webhook log(s) would be reprocessed.');
    }
}
