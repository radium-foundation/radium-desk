<?php

namespace Tests\Unit;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\ServiceCaseCloseNotificationPreference;
use App\Enums\ServiceCaseCloseReasonForClosing;
use App\Enums\ServiceCaseCloseResolutionType;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\WorkspaceCloseCasePayloadAdapter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WorkspaceCloseCasePayloadAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function createIncident(array $overrides = []): Incident
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => $overrides['order_id'] ?? 'ORD-ADAPTER-1',
            'serial_number' => $overrides['serial_number'] ?? 'SN-ADAPTER-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'transaction_id' => 'TXN-ADAPTER',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        unset($overrides['order_id'], $overrides['serial_number']);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => $overrides['reference_no'] ?? app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Adapter test',
            'description' => 'Adapter test description.',
            'status' => IncidentStatus::InProgress,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            ...$overrides,
        ]);
    }

    public function test_adapter_maps_issue_resolved_without_exceptions(): void
    {
        $incident = $this->createIncident();
        $adapter = app(WorkspaceCloseCasePayloadAdapter::class);

        $legacy = $adapter->toLegacyPayload($incident, [
            'reason_for_closing' => ServiceCaseCloseReasonForClosing::IssueResolved->value,
            'notification_preference' => ServiceCaseCloseNotificationPreference::Both->value,
            'body' => 'Resolved successfully.',
        ]);

        $this->assertSame('Resolved successfully.', $legacy['body']);
        $this->assertTrue($legacy['notify_whatsapp']);
        $this->assertTrue($legacy['notify_email']);
        $this->assertArrayNotHasKey('serial_number_unavailable', $legacy);
        $this->assertArrayNotHasKey('reference_number_unavailable', $legacy);
    }

    public function test_adapter_maps_reference_number_pending_to_legacy_exception(): void
    {
        $incident = $this->createIncident(['reference_no' => '']);
        $adapter = app(WorkspaceCloseCasePayloadAdapter::class);

        $legacy = $adapter->toLegacyPayload($incident, [
            'reason_for_closing' => ServiceCaseCloseReasonForClosing::ReferenceNumberPending->value,
            'expected_from' => 'customer',
            'expected_date' => '2026-07-20',
            'body' => 'Waiting for reference.',
        ]);

        $this->assertTrue($legacy['reference_number_unavailable']);
        $this->assertSame('other', $legacy['reference_exception_reason']);
        $this->assertStringContainsString('customer', $legacy['reference_exception_reason_custom']);
    }

    public function test_adapter_extracts_outcome_data_for_reporting(): void
    {
        $adapter = app(WorkspaceCloseCasePayloadAdapter::class);

        $outcomeData = $adapter->extractOutcomeData([
            'reason_for_closing' => ServiceCaseCloseReasonForClosing::IssueResolved->value,
            'resolution_type' => ServiceCaseCloseResolutionType::GuidanceProvided->value,
            'notification_preference' => ServiceCaseCloseNotificationPreference::Email->value,
            'body' => 'Guidance shared with customer.',
        ]);

        $this->assertSame(ServiceCaseCloseReasonForClosing::IssueResolved, $outcomeData['reason_for_closing']);
        $this->assertSame(ServiceCaseCloseResolutionType::GuidanceProvided, $outcomeData['resolution_type']);
        $this->assertSame(ServiceCaseCloseNotificationPreference::Email, $outcomeData['notification_preference']);
        $this->assertSame('Guidance shared with customer.', $outcomeData['closing_summary']);
    }

    public function test_adapter_blocks_customer_not_responding_without_follow_up(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->createIncident();
        $adapter = app(WorkspaceCloseCasePayloadAdapter::class);

        $this->expectException(ValidationException::class);

        $adapter->validateBeforeClose($incident, $agent, [
            'reason_for_closing' => ServiceCaseCloseReasonForClosing::CustomerNotResponding->value,
            'contact_attempt' => 'call',
            'attempts' => 3,
            'body' => 'No response.',
        ]);
    }
}
