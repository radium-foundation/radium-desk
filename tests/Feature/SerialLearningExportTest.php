<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\User;
use App\Services\SerialValidation\SerialLearningExportService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SerialLearningExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_export_excludes_customer_pii(): void
    {
        $order = Order::query()->create([
            'order_id' => 'RD-LEARN-001',
            'customer_name' => 'Secret Customer',
            'customer_phone' => '9999999999',
            'customer_email' => 'secret@example.com',
            'serial_number' => '1234567890',
            'device_model' => 'MFS 110',
            'product_name' => 'MFS 110',
            'status' => 'active',
            'created_by' => User::factory()->create()->id,
        ]);

        AuditLog::query()->create([
            'event' => 'serial.corrected_by_ira',
            'auditable_type' => Order::class,
            'auditable_id' => $order->id,
            'old_values' => ['serial_number' => '123456789O'],
            'new_values' => ['serial_number' => '1234567890', 'note' => 'Corrected by IRA'],
        ]);

        $export = app(SerialLearningExportService::class)->export();
        $encoded = json_encode($export->toArray());

        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('Secret Customer', $encoded);
        $this->assertStringNotContainsString('9999999999', $encoded);
        $this->assertStringNotContainsString('secret@example.com', $encoded);
        $this->assertStringContainsString('RD-LEARN-001', $encoded);
        $this->assertGreaterThanOrEqual(1, $export->correctedHistoryCount);
    }

    public function test_export_command_writes_json_file(): void
    {
        Order::query()->create([
            'order_id' => 'RD-LEARN-002',
            'customer_name' => 'Another Customer',
            'serial_number' => '9876543210',
            'device_model' => 'MFS 110',
            'product_name' => 'MFS 110',
            'status' => 'active',
            'created_by' => User::factory()->create()->id,
        ]);

        $outputPath = storage_path('app/testing/serial-learning-export.json');

        if (file_exists($outputPath)) {
            unlink($outputPath);
        }

        $this->artisan('serial:export-learning', [
            '--output' => $outputPath,
            '--pretty' => true,
        ])->assertSuccessful();

        $this->assertFileExists($outputPath);

        $contents = file_get_contents($outputPath);
        $this->assertIsString($contents);
        $this->assertStringContainsString('valid_serials', $contents);
        $this->assertStringContainsString('product_mapping', $contents);
        $this->assertStringContainsString('insight_analysis', $contents);

        unlink($outputPath);
    }
}
