<?php

namespace Tests\Unit\CommunicationActions\RefundConfirmation;

use App\Enums\CommunicationActionKey;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\RefundStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\CommunicationActions\CommunicationActionRegistry;
use App\Services\CommunicationActions\CommunicationActionVariableResolver;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefundConfirmationVariableResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'communication_actions.company_name' => 'Radium Box',
            'communication_actions.support_contact' => 'support@radiumbox.com',
        ]);
    }

    public function test_resolves_refund_confirmation_variables_from_approved_refund(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-REFUND-VARS',
            'serial_number' => '7881954',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Jane Refund',
            'customer_phone' => '9876543210',
            'customer_email' => 'jane@example.com',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-REFUND-001',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Refund confirmation variable case',
            'description' => 'Refund confirmation variable case.',
            'status' => IncidentStatus::Resolved,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
            'assigned_to_user_id' => $admin->id,
        ]);

        RefundRequest::query()->create([
            'order_id' => $order->id,
            'incident_id' => $incident->id,
            'reference_no' => 'REF-2026-000300',
            'amount' => 1999.99,
            'reason' => 'Approved refund for variable resolution.',
            'status' => RefundStatus::Approved,
            'requested_by' => $admin->id,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'refund_transaction_id' => 'RFTX-300',
        ]);

        $definition = app(CommunicationActionRegistry::class)->get(CommunicationActionKey::RefundConfirmation);

        $variables = app(CommunicationActionVariableResolver::class)->resolve(
            definition: $definition,
            incident: $incident,
            operator: $admin,
        );

        $this->assertSame('Jane Refund', $variables['customer_name']);
        $this->assertSame('Radium Box', $variables['company_name']);
        $this->assertSame('1,999.99', $variables['refund_amount']);
        $this->assertSame('REF-2026-000300', $variables['refund_reference']);
        $this->assertSame('RD-REFUND-VARS', $variables['order_number']);
        $this->assertSame('SC-REFUND-001', $variables['case_number']);
        $this->assertSame('support@radiumbox.com', $variables['support_contact']);
        $this->assertSame([
            'Jane Refund',
            '1,999.99',
            'REF-2026-000300',
        ], $variables['whatsapp_body_values']);
        $this->assertArrayNotHasKey('whatsapp_button_values', $variables);
    }
}
