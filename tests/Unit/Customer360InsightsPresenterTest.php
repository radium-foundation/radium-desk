<?php

namespace Tests\Unit;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OrderStatus;
use App\Enums\RefundStatus;
use App\Models\AuditLog;
use App\Models\CustomerDataCorrection;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\SerialValidation\RequestCorrectSerialAuditService;
use App\Support\Customer360\Customer360InsightsPresenter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class Customer360InsightsPresenterTest extends TestCase
{
    use RefreshDatabase;
    public function test_present_includes_repeat_customer_and_preferred_channel_insights(): void
    {
        $insights = app(Customer360InsightsPresenter::class)->present(
            [
                'preferred_channel' => 'WhatsApp',
                'total_appointments' => 0,
                'missed_appointments' => 0,
            ],
            [
                'total_orders' => 3,
                'open_cases' => 0,
                'closed_cases' => 1,
            ],
            null,
        );

        $keys = collect($insights)->pluck('key')->all();

        $this->assertContains('repeat_customer', $keys);
        $this->assertContains('preferred_communication_channel', $keys);
        $this->assertLessThanOrEqual(8, count($insights));
    }

    public function test_present_hides_insights_when_criteria_are_not_met(): void
    {
        $insights = app(Customer360InsightsPresenter::class)->present(
            [
                'preferred_channel' => null,
                'total_appointments' => 2,
                'missed_appointments' => 1,
            ],
            [
                'total_orders' => 1,
                'open_cases' => 1,
                'closed_cases' => 0,
            ],
            null,
        );

        $this->assertSame([], $insights);
    }

    public function test_present_detects_refund_free_history_and_remote_first_resolution(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-INSIGHTS-1',
            'serial_number' => 'SN-INSIGHTS-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876512345',
            'status' => OrderStatus::Active,
            'created_by' => $agent->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Resolved remotely',
            'description' => 'Resolved without field visit.',
            'status' => IncidentStatus::Resolved,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $insights = app(Customer360InsightsPresenter::class)->present(
            [
                'preferred_channel' => null,
                'total_appointments' => 0,
                'missed_appointments' => 0,
            ],
            [
                'total_orders' => 1,
                'open_cases' => 0,
                'closed_cases' => 1,
            ],
            '9876512345',
        );

        $keys = collect($insights)->pluck('key')->all();

        $this->assertContains('refund_free_history', $keys);
        $this->assertContains('remote_first_resolution_history', $keys);
    }

    public function test_present_detects_identity_and_serial_corrections(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-INSIGHTS-2',
            'serial_number' => 'SN-INSIGHTS-2',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876512346',
            'status' => OrderStatus::Active,
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Correction case',
            'description' => 'Correction case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        CustomerDataCorrection::query()->create([
            'order_id' => $order->id,
            'corrected_by' => $agent->id,
            'status' => 'completed',
            'reason' => 'Customer name updated.',
        ]);

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => RequestCorrectSerialAuditService::EVENT_REQUEST_SENT,
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'old_values' => ['serial_number' => 'SN-OLD'],
            'new_values' => ['old_serial' => 'SN-OLD'],
            'created_at' => Carbon::now(),
        ]);

        $insights = app(Customer360InsightsPresenter::class)->present(
            [
                'preferred_channel' => null,
                'total_appointments' => 0,
                'missed_appointments' => 0,
            ],
            [
                'total_orders' => 1,
                'open_cases' => 1,
                'closed_cases' => 0,
            ],
            '9876512346',
        );

        $keys = collect($insights)->pluck('key')->all();

        $this->assertContains('previous_identity_correction', $keys);
        $this->assertContains('previous_serial_correction', $keys);
        $this->assertContains('refund_free_history', $keys);
    }

    public function test_present_detects_multiple_active_devices(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        foreach (['SN-DEV-1', 'SN-DEV-2'] as $index => $serial) {
            Order::query()->create([
                'order_id' => 'RD-INSIGHTS-DEV-'.$index,
                'serial_number' => $serial,
                'product_name' => 'MFS 110',
                'device_model' => 'MFS 110',
                'customer_phone' => '9876512347',
                'status' => OrderStatus::Active,
                'created_by' => $agent->id,
            ]);
        }

        $insights = app(Customer360InsightsPresenter::class)->present(
            [
                'preferred_channel' => null,
                'total_appointments' => 0,
                'missed_appointments' => 0,
            ],
            [
                'total_orders' => 2,
                'total_devices' => 2,
                'open_cases' => 0,
                'closed_cases' => 0,
            ],
            '9876512347',
        );

        $keys = collect($insights)->pluck('key')->all();

        $this->assertContains('repeat_customer', $keys);
        $this->assertContains('multiple_active_devices', $keys);
    }

    public function test_present_excludes_refund_free_history_when_refund_exists(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-INSIGHTS-REFUND',
            'serial_number' => 'SN-REFUND',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876512348',
            'status' => OrderStatus::Active,
            'created_by' => $agent->id,
        ]);

        RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-2026-000001',
            'amount' => 100,
            'reason' => 'Test refund.',
            'status' => RefundStatus::Pending,
            'requested_by' => $agent->id,
        ]);

        $insights = app(Customer360InsightsPresenter::class)->present(
            [
                'preferred_channel' => null,
                'total_appointments' => 0,
                'missed_appointments' => 0,
            ],
            [
                'total_orders' => 1,
                'open_cases' => 0,
                'closed_cases' => 0,
            ],
            '9876512348',
        );

        $keys = collect($insights)->pluck('key')->all();

        $this->assertNotContains('refund_free_history', $keys);
    }
}
