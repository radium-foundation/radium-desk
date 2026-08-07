<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Jobs\SendServiceReferenceDriverGuideBatchJob;
use App\Jobs\SendServiceReferenceDriverGuideJob;
use App\Models\AuditLog;
use App\Models\DeviceModel;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\AssignReferenceBatchCoalescer;
use App\Services\CommunicationActions\ReferenceNumberCommunicationService;
use App\Services\IncidentReferenceService;
use App\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * DriverGuide batch chunking (DRIVERGUIDE_BATCH_SIZE) — dispatch shape + retry isolation.
 */
class DriverGuideBatchChunkingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'interakt.api_key' => 'test-interakt-key',
            'interakt.templates.driver_installation_guide.name' => 'driver_installation_guide_template',
            'interakt.templates.driver_installation_guide.display_name' => 'Driver Installation Guide',
            'interakt.templates.driver_installation_guide.language_code' => 'en',
            'interakt.templates.driver_installation_guide.enabled' => true,
            'communication_actions.driver_installation_guide.batch_size' => 20,
        ]);
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: list<int>}>
     */
    public static function chunkDispatchProvider(): array
    {
        return [
            '1 order' => [1, 1, [1]],
            '19 orders' => [19, 1, [19]],
            '20 orders' => [20, 1, [20]],
            '21 orders' => [21, 2, [20, 1]],
            '42 orders' => [42, 3, [20, 20, 2]],
            '85 orders' => [85, 5, [20, 20, 20, 20, 5]],
        ];
    }

    #[DataProvider('chunkDispatchProvider')]
    public function test_flush_dispatches_ordered_chunks_without_duplicates(
        int $orderCount,
        int $expectedJobs,
        array $expectedChunkSizes,
    ): void {
        Queue::fake();

        $admin = User::factory()->create();
        $orderIds = range(10_001, 10_000 + $orderCount);
        $items = array_map(
            fn (int $orderId): array => [
                'order_id' => $orderId,
                'service_reference' => 'TXN-CHUNK',
            ],
            $orderIds,
        );

        $coalescer = app(AssignReferenceBatchCoalescer::class);
        $coalescer->begin();

        foreach ($items as $item) {
            $coalescer->deferDriverGuide($item['order_id'], $item['service_reference'], $admin->id);
        }

        $coalescer->flushCommunications(app(SettingService::class));
        $coalescer->end();

        Queue::assertNotPushed(SendServiceReferenceDriverGuideJob::class);
        Queue::assertPushed(SendServiceReferenceDriverGuideBatchJob::class, $expectedJobs);

        /** @var list<SendServiceReferenceDriverGuideBatchJob> $pushed */
        $pushed = Queue::pushed(SendServiceReferenceDriverGuideBatchJob::class)->values()->all();

        $this->assertCount($expectedJobs, $pushed);
        $this->assertTrue(
            collect($pushed)->every(fn (SendServiceReferenceDriverGuideBatchJob $job): bool => $job->actorId === $admin->id),
        );
        $this->assertSame(
            $expectedChunkSizes,
            array_map(fn (SendServiceReferenceDriverGuideBatchJob $job): int => count($job->items), $pushed),
        );

        $flattened = [];
        foreach ($pushed as $job) {
            foreach ($job->items as $item) {
                $flattened[] = $item['order_id'];
            }
        }

        $this->assertSame($orderIds, $flattened, 'chunk dispatch must preserve assignment order with no duplicates');
        $this->assertSame(count($orderIds), count(array_unique($flattened)));
    }

    public function test_batch_size_is_read_from_config_not_hardcoded(): void
    {
        Queue::fake();
        config(['communication_actions.driver_installation_guide.batch_size' => 10]);

        $admin = User::factory()->create();
        $coalescer = app(AssignReferenceBatchCoalescer::class);
        $coalescer->begin();

        for ($i = 1; $i <= 25; $i++) {
            $coalescer->deferDriverGuide(20_000 + $i, 'TXN-CFG', $admin->id);
        }

        $coalescer->flushCommunications(app(SettingService::class));
        $coalescer->end();

        Queue::assertPushed(SendServiceReferenceDriverGuideBatchJob::class, 3);

        $sizes = array_map(
            fn (SendServiceReferenceDriverGuideBatchJob $job): int => count($job->items),
            Queue::pushed(SendServiceReferenceDriverGuideBatchJob::class)->values()->all(),
        );

        $this->assertSame([10, 10, 5], $sizes);
    }

    public function test_failed_chunk_retry_is_isolated_and_idempotent(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $deviceModel = DeviceModel::query()->create([
            'name' => 'MFS 110 Chunk Retry',
            'driver_download_url' => 'https://radiumbox.com/drivers/mfs-110-chunk',
            'display_order' => 1,
            'is_active' => true,
        ]);

        [$orderA] = $this->createOrderWithIncident($admin, $deviceModel);
        [$orderB] = $this->createOrderWithIncident($admin, $deviceModel);
        [$orderC] = $this->createOrderWithIncident($admin, $deviceModel);

        $this->verifyLegacyOrder($admin, $orderA);
        $this->verifyLegacyOrder($admin, $orderB);
        $this->verifyLegacyOrder($admin, $orderC);
        $this->enableNotificationChannels();

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-chunk-retry'], 200),
        ]);

        $chunkOne = new SendServiceReferenceDriverGuideBatchJob(
            items: [
                ['order_id' => $orderA->id, 'service_reference' => 'TXN-CHUNK-ISO'],
                ['order_id' => $orderB->id, 'service_reference' => 'TXN-CHUNK-ISO'],
            ],
            actorId: $admin->id,
        );
        $chunkTwo = new SendServiceReferenceDriverGuideBatchJob(
            items: [
                ['order_id' => $orderC->id, 'service_reference' => 'TXN-CHUNK-ISO'],
            ],
            actorId: $admin->id,
        );

        app()->call([$chunkOne, 'handle']);
        app()->call([$chunkOne, 'handle']); // retry same chunk — must not resend
        app()->call([$chunkTwo, 'handle']);

        $this->assertSame(
            1,
            AuditLog::query()
                ->where('event', ReferenceNumberCommunicationService::IDEMPOTENCY_AUDIT_EVENT)
                ->where('auditable_id', $orderA->id)
                ->count(),
        );
        $this->assertSame(
            1,
            AuditLog::query()
                ->where('event', ReferenceNumberCommunicationService::IDEMPOTENCY_AUDIT_EVENT)
                ->where('auditable_id', $orderB->id)
                ->count(),
        );
        $this->assertSame(
            1,
            AuditLog::query()
                ->where('event', ReferenceNumberCommunicationService::IDEMPOTENCY_AUDIT_EVENT)
                ->where('auditable_id', $orderC->id)
                ->count(),
        );

        $this->assertSame(
            3,
            AuditLog::query()
                ->where('event', ReferenceNumberCommunicationService::IDEMPOTENCY_AUDIT_EVENT)
                ->count(),
            'exactly one guide-sent audit per order across chunk retry + sibling chunk',
        );

        $this->assertSame(2, count($chunkOne->items));
        $this->assertSame(1, count($chunkTwo->items));
        $this->assertSame(
            [$orderA->id, $orderB->id],
            array_column($chunkOne->items, 'order_id'),
        );
        $this->assertSame(
            [$orderC->id],
            array_column($chunkTwo->items, 'order_id'),
        );
    }

    /**
     * @return array{0: Order, 1: Incident}
     */
    private function createOrderWithIncident(User $admin, DeviceModel $deviceModel): array
    {
        $order = Order::query()->create([
            'order_id' => 'RD-CHUNK-'.uniqid(),
            'serial_number' => 'SN-CHUNK-'.uniqid(),
            'product_name' => $deviceModel->name,
            'device_model' => $deviceModel->name,
            'device_model_id' => $deviceModel->id,
            'customer_name' => 'Chunk Customer',
            'customer_phone' => '9876543210',
            'customer_email' => 'chunk-'.uniqid().'@example.com',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Chunk guide',
            'description' => 'Chunk guide.',
            'status' => IncidentStatus::InProgress,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
            'assigned_to_user_id' => $admin->id,
        ]);

        return [$order, $incident];
    }

    private function verifyLegacyOrder(User $admin, Order $order): void
    {
        $this->actingAs($admin)
            ->postJson(route('orders.legacy-verification.store', $order), [
                'confirmed' => true,
            ])
            ->assertOk();
    }

    private function enableNotificationChannels(): void
    {
        $settings = [
            'notifications.whatsapp.enabled' => true,
            'notifications.email.enabled' => true,
            'whatsapp.api_enabled' => true,
            'whatsapp.manual_templates_enabled' => true,
        ];

        foreach ($settings as $key => $value) {
            \App\Models\SystemSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $value ? '1' : '0'],
            );

            app(\App\Services\SystemSettingsService::class)->forget($key);
        }
    }
}
