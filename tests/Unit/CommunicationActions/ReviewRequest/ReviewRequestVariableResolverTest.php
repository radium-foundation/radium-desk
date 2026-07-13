<?php

namespace Tests\Unit\CommunicationActions\ReviewRequest;

use App\Enums\CommunicationActionKey;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\CommunicationActions\CommunicationActionRegistry;
use App\Services\CommunicationActions\CommunicationActionVariableResolver;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewRequestVariableResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'communication_actions.urls.review' => 'https://g.page/r/radiumbox/review',
            'communication_actions.company_name' => 'Radium Box',
            'communication_actions.support_contact' => 'support@radiumbox.com',
        ]);
    }

    public function test_resolves_review_request_variables(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-REVIEW-VARS',
            'serial_number' => '7881954',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Jane Customer',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Review request variable case',
            'description' => 'Review request variable case.',
            'status' => IncidentStatus::Resolved,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $definition = app(CommunicationActionRegistry::class)->get(CommunicationActionKey::ReviewRequest);

        $variables = app(CommunicationActionVariableResolver::class)->resolve(
            definition: $definition,
            incident: $incident,
            operator: $agent,
        );

        $this->assertSame('Jane Customer', $variables['customer_name']);
        $this->assertSame('https://g.page/r/radiumbox/review', $variables['review_url']);
        $this->assertSame('Radium Box', $variables['company_name']);
        $this->assertSame('support@radiumbox.com', $variables['support_contact']);
        $this->assertSame([
            'Jane Customer',
            'https://g.page/r/radiumbox/review',
        ], $variables['whatsapp_body_values']);
    }
}
