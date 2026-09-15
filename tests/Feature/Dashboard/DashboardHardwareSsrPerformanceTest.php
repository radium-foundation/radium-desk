<?php

namespace Tests\Feature\Dashboard;

use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentSerialStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentSerial;
use App\Models\Incident;
use App\Models\InventoryProduct;
use App\Models\Order;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Dashboard\DashboardSnapshotStore;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SSR budget for GET /dashboard vs /dashboard?queue=hardware.
 * Hardware chip + workspace previously rebuilt the full fulfilment inspect set twice.
 */
class DashboardHardwareSsrPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private const FULFILMENT_COUNT = 24;

    private const SERVICE_CASE_COUNT = 24;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->withoutVite();
        Cache::flush();
        app(DashboardSnapshotStore::class)->forget();
    }

    public function test_ready_queue_and_hardware_ssr_stay_within_query_budgets(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->seedCatalog();
        $this->seedHardwareFulfilments(self::FULFILMENT_COUNT);
        $this->seedServiceCases($admin, self::SERVICE_CASE_COUNT);

        $this->actingAs($admin)->get(route('dashboard'))->assertOk();
        $this->actingAs($admin)->get(route('dashboard', ['queue' => 'hardware']))->assertOk();

        $ready = $this->profileGet(route('dashboard'));
        $hardware = $this->profileGet(route('dashboard', ['queue' => 'hardware']));

        $this->assertSame(200, $ready['status']);
        $this->assertSame(200, $hardware['status']);
        $this->assertGreaterThan(20_000, $ready['bytes']);
        $this->assertGreaterThan(20_000, $hardware['bytes']);

        $this->assertLessThan(
            50,
            $ready['queries'],
            $this->budgetMessage('ready queue', $ready),
        );
        $this->assertLessThan(
            50,
            $hardware['queries'],
            $this->budgetMessage('hardware queue', $hardware),
        );
        $this->assertLessThan(
            12,
            $this->countSql($ready['log'], 'statutory_invoices'),
            'Ready queue should not N+1 statutory invoices for the hardware chip.',
        );
        $this->assertLessThan(
            12,
            $this->countSql($hardware['log'], 'statutory_invoices'),
            'Hardware workspace should not N+1 statutory invoices.',
        );
        $this->assertLessThan(
            8,
            $this->countSql($hardware['log'], 'hardware_fulfilment_serials'),
            'Hardware workspace should not query allocated serials per fulfilment.',
        );
    }

    public function test_hardware_chip_count_matches_unfiltered_work_queue(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->seedCatalog();
        $this->seedHardwareFulfilments(3);

        $readyHtml = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->getContent();
        $hardwareHtml = $this->actingAs($admin)
            ->get(route('dashboard', ['queue' => 'hardware']))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/data-dashboard-case-filter-count="hardware">\(3\)/',
            $readyHtml,
        );
        $this->assertMatchesRegularExpression(
            '/data-dashboard-case-filter-count="hardware">\(3\)/',
            $hardwareHtml,
        );
        $this->assertSame(3, preg_match_all('/\sdata-hardware-select(\s|>)/', $hardwareHtml));
    }

    /**
     * @return array{status: int, ms: float, bytes: int, queries: int, log: list<array{query: string, time: float}>}
     */
    private function profileGet(string $url): array
    {
        Cache::flush();
        app(DashboardSnapshotStore::class)->forget();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $started = hrtime(true);
        $response = $this->get($url);
        $elapsedMs = (hrtime(true) - $started) / 1e6;
        $log = DB::getQueryLog();
        DB::flushQueryLog();
        DB::disableQueryLog();

        return [
            'status' => $response->getStatusCode(),
            'ms' => $elapsedMs,
            'bytes' => strlen((string) $response->getContent()),
            'queries' => count($log),
            'log' => $log,
        ];
    }

    /**
     * @param  array{ms: float, bytes: int, queries: int, log: list<array{query: string, time: float}>}  $profile
     */
    private function budgetMessage(string $label, array $profile): string
    {
        $top = collect($profile['log'])
            ->map(fn (array $q): string => preg_replace('/\s+/', ' ', $q['query']) ?? $q['query'])
            ->countBy()
            ->sortDesc()
            ->take(8)
            ->map(fn (int $count, string $sql): string => "{$count}× {$sql}")
            ->implode(' | ');

        return sprintf(
            '%s SSR queries=%d ms=%.1f bytes=%d top=%s',
            $label,
            $profile['queries'],
            $profile['ms'],
            $profile['bytes'],
            $top,
        );
    }

    /**
     * @param  list<array{query: string}>  $log
     */
    private function countSql(array $log, string $needle): int
    {
        return collect($log)
            ->filter(fn (array $q): bool => str_contains(strtolower($q['query']), $needle))
            ->count();
    }

    private function seedCatalog(): void
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'RBMFS110L1',
            'name' => 'MFS110',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 2549,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        ChannelSkuMap::query()->create([
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'model_id' => 946,
            'inventory_product_id' => $product->id,
            'catalog_sku' => 'RBMFS110L1',
            'channel_sku' => '946',
        ]);
    }

    private function seedHardwareFulfilments(int $count): void
    {
        $creator = User::factory()->create(['is_active' => true]);

        for ($i = 1; $i <= $count; $i++) {
            $sourceId = sprintf('RDE90%04d', $i);
            $order = Order::query()->create([
                'order_id' => $sourceId,
                'product_name' => 'MFS 110',
                'status' => 'active',
                'customer_name' => 'Buyer '.$sourceId,
                'cashfree_payment_id' => 'cf_'.$sourceId,
                'created_by' => $creator->id,
            ]);
            $order->forceFill(['created_at' => '2026-09-07 10:00:00'])->save();

            $invoice = StatutoryInvoice::query()->create([
                'invoice_number' => 'INV-PERF-'.$i,
                'document_type' => 'tax_invoice',
                'status' => 'issued',
                'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
                'source_type' => 'commerce_order',
                'source_id' => $sourceId,
                'idempotency_key' => 'invoice:'.$sourceId,
                'invoice_value' => 2549,
                'issued_at' => now(),
            ]);

            $commerce = CommerceOrder::query()->create([
                'order_no' => 'CO-'.$sourceId,
                'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
                'source_type' => 'commerce_order',
                'source_id' => $sourceId,
                'idempotency_key' => 'statutory:radiumbox_com:commerce_order:'.$sourceId,
                'payload_hash' => hash('sha256', $sourceId),
                'status' => CommerceOrderStatus::InvoicePending,
                'invoice_eligible' => true,
                'payment_status' => 'paid',
                'currency' => 'INR',
                'customer_name' => 'Buyer '.$sourceId,
                'received_at' => now(),
                'ordered_at' => '2026-09-07 04:30:00',
                'support_order_id' => $order->id,
                'statutory_invoice_id' => $invoice->id,
            ]);
            CommerceOrderItem::query()->create([
                'commerce_order_id' => $commerce->id,
                'line_no' => 1,
                'sku' => 'RBMFS110L1',
                'catalog_sku' => 'MFS110',
                'model_id' => 946,
                'shipping_line_kind' => HardwareFulfilmentEligibility::PHYSICAL_LINE_KIND,
                'requires_shipping' => true,
                'description' => 'MFS110',
                'qty' => 1,
                'unit_price' => 2549,
                'gst_percentage' => 18,
                'taxable_value' => 2160.17,
                'tax_total' => 388.83,
                'line_total' => 2549,
            ]);

            $fulfilment = HardwareFulfilment::query()->create([
                'commerce_order_id' => $commerce->id,
                'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
                'source_type' => 'commerce_order',
                'source_id' => $sourceId,
                'idempotency_key' => $commerce->idempotency_key,
                'state' => HardwareFulfilmentState::InvoiceIssued,
                'support_order_id' => $order->id,
                'statutory_invoice_id' => $invoice->id,
                'ingested_at' => now(),
                'invoice_issued_at' => now(),
            ]);
            HardwareFulfilmentSerial::query()->create([
                'hardware_fulfilment_id' => $fulfilment->id,
                'line_no' => 1,
                'position' => 1,
                'serial_number' => 'SN-PERF-'.$i,
                'status' => HardwareFulfilmentSerialStatus::Allocated,
                'allocated_at' => now(),
            ]);
        }
    }

    private function seedServiceCases(User $admin, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $order = Order::query()->create([
                'order_id' => 'RB-PERF-'.$i,
                'serial_number' => 'SN-SVC-'.$i,
                'product_name' => 'MFS 110',
                'device_model' => 'MFS 110',
                'status' => 'active',
                'created_by' => $admin->id,
            ]);
            Incident::query()->create([
                'order_id' => $order->id,
                'order_record_id' => $order->id,
                'reference_no' => app(IncidentReferenceService::class)->generate(),
                'category' => 'General',
                'source' => IncidentSource::Call,
                'title' => 'Perf case '.$i,
                'description' => 'Perf case '.$i,
                'status' => IncidentStatus::Open,
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
                'assigned_to_user_id' => $admin->id,
            ]);
        }
    }
}
