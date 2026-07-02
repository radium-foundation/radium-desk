<?php

namespace Tests\Unit\AI;

use App\Data\AI\OperationalIntelligenceDTO;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\ServiceCaseSlaStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\AI\IRAExecutiveSummaryService;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AIContextFactory;
use Tests\TestCase;

class IRAExecutiveSummaryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_builds_serial_pending_executive_summary(): void
    {
        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-EXEC-SERIAL',
            'serial_number' => null,
            'product_name' => 'FM220',
            'device_model' => 'FM220',
            'customer_name' => 'Serial Pending Customer',
            'customer_phone' => '9123456780',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Serial pending',
            'description' => 'Missing serial.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
            'created_at' => now()->subDays(4),
        ]);

        $context = AIContextFactory::make([
            'serialMissing' => true,
            'deviceModel' => 'FM220',
            'warrantyStatus' => 'Not Available',
            'customerSummary' => ['open_cases' => 1],
            'operationalIntelligence' => new OperationalIntelligenceDTO(
                waitingState: null,
                slaState: ServiceCaseSlaStatus::Overdue->label(),
                priority: 'Normal',
                assignment: null,
                queuePosition: null,
                automationHistory: [],
                automationStatus: 'Idle',
                timelineSummary: 'No recent activity.',
                internalRemarksSummary: 'No internal remarks.',
            ),
        ]);

        $response = app(\App\Services\AI\AIService::class)->buildBundle($incident)->response;
        $summary = app(IRAExecutiveSummaryService::class)->build(
            incident: $incident,
            response: $response,
            context: $context,
            customerSummary: ['open_cases' => 1],
        );

        $this->assertCount(4, $summary->executiveSummary);
        $this->assertStringContainsString('FM 220', $summary->executiveSummary[0]);
        $this->assertStringContainsString('serial number is still missing', $summary->executiveSummary[1]);
        $this->assertStringContainsString('Warranty cannot yet be verified', $summary->executiveSummary[2]);
        $this->assertStringContainsString('beyond SLA', $summary->executiveSummary[3]);
        $this->assertStringContainsString('serial-number pending case', $summary->opinion);
        $this->assertStringContainsString('Request the serial immediately', $summary->recommendation);
    }

    public function test_translation_service_translates_executive_summary_payload(): void
    {
        $translated = app(\App\Services\AI\IRAExecutiveSummaryTranslationService::class)
            ->translatePayloadToHindi([
                'executive_summary' => [
                    'Customer purchased an FM220 and currently has one active repair.',
                    'The device serial number is still missing, causing service delay.',
                ],
                'opinion' => 'This appears to be a straightforward serial-number pending case. Obtaining the serial should unblock warranty validation and allow engineering to proceed.',
                'recommendation' => 'Request the serial immediately, verify warranty once received, and proactively update the customer regarding SLA.',
            ]);

        $this->assertStringContainsString('ग्राहक ने खरीदा', $translated['executive_summary'][0]);
        $this->assertStringContainsString('सीरियल-नंबर लंबित केस', $translated['opinion']);
        $this->assertStringContainsString('तुरंत सीरियल माँगें', $translated['recommendation']);
    }
}
