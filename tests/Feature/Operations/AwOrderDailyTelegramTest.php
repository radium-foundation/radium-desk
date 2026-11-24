<?php

namespace Tests\Feature\Operations;

use App\Models\OperationalTelegramDeliveryReceipt;
use App\Models\Order;
use App\Models\User;
use App\Services\Operations\AwOrderDailyCountService;
use App\Services\Operations\AwOrderDailyTelegramService;
use App\Services\Operations\SuperAdminTelegramRecipientService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AwOrderDailyTelegramTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'app.timezone' => 'Asia/Kolkata',
            'services.telegram.bot_token' => 'test-bot-token',
            'operations_aw_orders.daily_telegram.enabled' => false,
            'operations_aw_orders.daily_telegram.report_timezone' => 'Asia/Kolkata',
        ]);

        $this->enableTelegramNotifications();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_feature_flag_defaults_off_and_blocks_http(): void
    {
        $this->assertFalse((bool) config('operations_aw_orders.daily_telegram.enabled', true));

        Http::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-24 08:00:00', 'Asia/Kolkata'));

        $this->createSuperAdminWithTelegram('900001');
        $this->seedAwOrder('2026-11-23 12:00:00');

        $exit = Artisan::call('operations:send-aw-order-daily-telegram');

        $this->assertSame(0, $exit);
        Http::assertNothingSent();
        $this->assertDatabaseCount('operational_telegram_delivery_receipts', 0);
    }

    public function test_counts_previous_ist_day_with_half_open_bounds(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-24 08:00:00', 'Asia/Kolkata'));

        $this->seedAwOrder('2026-11-23 00:00:00');
        $this->seedAwOrder('2026-11-23 23:59:59');
        $this->seedAwOrder('2026-11-22 23:59:59');
        $this->seedAwOrder('2026-11-24 00:00:00');

        $service = app(AwOrderDailyCountService::class);
        $reportDate = $service->previousReportDate();

        $this->assertSame('2026-11-23', $reportDate->toDateString());
        $this->assertSame(2, $service->countForReportDate($reportDate));
    }

    public function test_excludes_soft_deleted_and_non_aw_references(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-24 08:00:00', 'Asia/Kolkata'));

        $this->seedAwOrder('2026-11-23 10:00:00');
        $nonAw = new Order([
            'order_id' => 'RD-X1',
            'serial_number' => 'SN-X1',
            'product_name' => 'Device',
            'device_model' => 'Model',
            'transaction_id' => 'RD-123',
        ]);
        $nonAw->created_at = Carbon::parse('2026-11-23 10:00:00', 'Asia/Kolkata');
        $nonAw->save();

        $deleted = new Order([
            'order_id' => 'RD-X2',
            'serial_number' => 'SN-X2',
            'product_name' => 'Device',
            'device_model' => 'Model',
            'transaction_id' => 'AW-01-01-26-01',
        ]);
        $deleted->created_at = Carbon::parse('2026-11-23 11:00:00', 'Asia/Kolkata');
        $deleted->save();
        $deleted->delete();

        $count = app(AwOrderDailyCountService::class)
            ->countForReportDate(app(AwOrderDailyCountService::class)->previousReportDate());

        $this->assertSame(1, $count);
    }

    public function test_zero_count_summary_message_has_no_pii(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-24 08:00:00', 'Asia/Kolkata'));
        $countService = app(AwOrderDailyCountService::class);
        $message = $countService->formatSummaryMessage($countService->previousReportDate(), 0);

        $this->assertStringContainsString('Report date: 2026-11-23', $message);
        $this->assertStringContainsString('Orders created: 0', $message);
        $this->assertStringNotContainsString('customer', strtolower($message));
    }

    public function test_superadmin_selected_admin_staff_excluded(): void
    {
        $super = $this->createSuperAdminWithTelegram('900010');
        $staff = User::factory()->create([
            'telegram_chat_id' => '900011',
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $staff->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $admin = User::factory()->create([
            'telegram_chat_id' => '900012',
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $resolver = app(SuperAdminTelegramRecipientService::class);
        $ids = $resolver->eligibleSuperAdminTelegramRecipients()->pluck('id')->all();

        $this->assertSame([$super->id], $ids);
        $this->assertFalse($resolver->isEligibleSuperAdminTelegramRecipient($staff));
        $this->assertFalse($resolver->isEligibleSuperAdminTelegramRecipient($admin));
    }

    public function test_missing_telegram_destination_results_in_zero_sends(): void
    {
        config(['operations_aw_orders.daily_telegram.enabled' => true]);
        Http::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-24 08:00:00', 'Asia/Kolkata'));

        User::factory()->create(['is_active' => true])
            ->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $result = app(AwOrderDailyTelegramService::class)->sendPreviousDaySummary();

        $this->assertSame(0, $result['sent']);
        Http::assertNothingSent();
    }

    public function test_enabled_sends_to_superadmin_and_records_receipt(): void
    {
        config(['operations_aw_orders.daily_telegram.enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-11-24 08:00:00', 'Asia/Kolkata'));

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 42]], 200),
        ]);

        $this->createSuperAdminWithTelegram('900020');
        $this->seedAwOrder('2026-11-23 14:00:00');

        $result = app(AwOrderDailyTelegramService::class)->sendPreviousDaySummary();

        $this->assertSame(1, $result['sent']);
        $this->assertSame(1, $result['count']);
        Http::assertSentCount(1);
        $this->assertDatabaseHas('operational_telegram_delivery_receipts', [
            'delivery_kind' => OperationalTelegramDeliveryReceipt::KIND_AW_ORDER_DAILY,
            'telegram_message_id' => '42',
        ]);
        $this->assertTrue(
            OperationalTelegramDeliveryReceipt::query()
                ->whereDate('report_date', '2026-11-23')
                ->exists(),
        );
    }

    public function test_second_run_same_day_is_idempotent(): void
    {
        config(['operations_aw_orders.daily_telegram.enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-11-24 08:00:00', 'Asia/Kolkata'));

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 99]], 200),
        ]);

        $this->createSuperAdminWithTelegram('900030');
        $service = app(AwOrderDailyTelegramService::class);

        $first = $service->sendPreviousDaySummary();
        $second = $service->sendPreviousDaySummary();

        $this->assertSame(1, $first['sent']);
        $this->assertSame(0, $second['sent']);
        $this->assertSame(1, $second['skipped']);
        Http::assertSentCount(1);
    }

    public function test_failed_telegram_api_does_not_record_receipt(): void
    {
        config(['operations_aw_orders.daily_telegram.enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-11-24 08:00:00', 'Asia/Kolkata'));

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => false], 500),
        ]);

        $this->createSuperAdminWithTelegram('900040');

        $result = app(AwOrderDailyTelegramService::class)->sendPreviousDaySummary();

        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['failed']);
        $this->assertDatabaseCount('operational_telegram_delivery_receipts', 0);
    }

    public function test_receipt_survives_cache_flush(): void
    {
        config(['operations_aw_orders.daily_telegram.enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-11-24 08:00:00', 'Asia/Kolkata'));

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]], 200),
        ]);

        $owner = $this->createSuperAdminWithTelegram('900050');
        app(AwOrderDailyTelegramService::class)->sendPreviousDaySummary();

        Cache::flush();

        $result = app(AwOrderDailyTelegramService::class)->sendPreviousDaySummary();
        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['skipped']);
        $this->assertTrue(
            OperationalTelegramDeliveryReceipt::query()
                ->where('user_id', $owner->id)
                ->whereDate('report_date', '2026-11-23')
                ->exists(),
        );
    }

    public function test_schedule_is_daily_at_eight_ist(): void
    {
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);
        $event = collect($schedule->events())->first(
            fn (Event $event): bool => str_contains((string) $event->command, 'operations:send-aw-order-daily-telegram'),
        );

        $this->assertNotNull($event);
        $this->assertSame('0 8 * * *', $event->getExpression());
        $this->assertSame(config('app.schedule_timezone', 'Asia/Kolkata'), $event->timezone);
    }

    private function createSuperAdminWithTelegram(string $chatId): User
    {
        $user = User::factory()->create([
            'telegram_chat_id' => $chatId,
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $user;
    }

    private function seedAwOrder(string $createdAtIst): void
    {
        static $n = 0;
        $n++;

        $order = new Order([
            'order_id' => 'RD-AW-'.$n,
            'serial_number' => 'SN-AW-'.$n,
            'product_name' => 'Device',
            'device_model' => 'Model',
            'transaction_id' => 'AW-01-01-26-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
        ]);
        $order->created_at = Carbon::parse($createdAtIst, 'Asia/Kolkata');
        $order->save();
    }
}
