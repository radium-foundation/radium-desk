<?php

namespace Tests\Unit\OemWarranty;

use App\Enums\OemWarrantyCaptureMethod;
use App\Enums\OemWarrantyLookupOutcome;
use App\Enums\OemWarrantyProvider;
use App\Models\OemWarrantyCheck;
use App\Models\Order;
use App\Models\User;
use App\Services\OemWarranty\OemWarrantyCheckService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OemWarrantyCheckServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_error_outcome_does_not_replace_existing_current_check(): void
    {
        $user = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-OEM-UNIT',
            'serial_number' => '7788001',
            'device_model' => 'MFS110',
            'product_name' => 'Scanner',
            'status' => 'active',
        ]);

        OemWarrantyCheck::query()->create([
            'order_id' => $order->id,
            'provider' => OemWarrantyProvider::Mantratec->value,
            'serial_number' => '7788001',
            'outcome' => OemWarrantyLookupOutcome::Found->value,
            'capture_method' => OemWarrantyCaptureMethod::ManualEntry->value,
            'verified_by_user_id' => $user->id,
            'checked_at' => now()->subDay(),
            'is_current' => true,
        ]);

        $this->expectException(ValidationException::class);

        app(OemWarrantyCheckService::class)->confirmManual($order, $user, OemWarrantyProvider::Mantratec, [
            'outcome' => OemWarrantyLookupOutcome::Error->value,
            'rows' => [],
        ]);
    }
}
