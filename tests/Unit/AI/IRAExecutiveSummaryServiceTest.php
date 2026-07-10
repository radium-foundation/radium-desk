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
use Illuminate\Support\Str;
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

        $this->assertLessThanOrEqual(4, count($summary->executiveSummary));
        $this->assertStringContainsString('serial number is still missing', $summary->executiveSummary[0]);
        $this->assertStringContainsString('beyond SLA', implode(' ', $summary->executiveSummary));
        $this->assertStringContainsString('blocked until the device serial number', $summary->opinion);
        $this->assertSame('Request the serial number from the customer immediately.', $summary->recommendation);
    }

    public function test_builds_suspicious_serial_warning_in_executive_summary(): void
    {
        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-EXEC-BAD-SERIAL',
            'serial_number' => '54SAXXC5514586',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Bad Serial Customer',
            'customer_phone' => '9123456781',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Bad serial',
            'description' => 'Invalid serial.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $context = AIContextFactory::make([
            'serialMissing' => false,
            'deviceModel' => 'MFS 110',
            'warrantyStatus' => 'Not Available',
            'customerSummary' => ['open_cases' => 1],
        ]);

        $response = app(\App\Services\AI\AIService::class)->buildBundle($incident)->response;
        $summary = app(IRAExecutiveSummaryService::class)->build(
            incident: $incident,
            response: $response,
            context: $context,
            customerSummary: ['open_cases' => 1],
        );

        $this->assertNotNull($summary->serialInsight);
        $this->assertSame('suspicious', $summary->serialInsight->status->value);
        $this->assertStringContainsString('Serial number needs verification', $summary->executiveSummary[0]);
        $this->assertStringContainsString('MFS 110', $summary->executiveSummary[0]);
        $this->assertStringNotContainsString('product code', $summary->executiveSummary[0]);
        $this->assertStringContainsString('incorrect', $summary->opinion);
        $this->assertSame(
            'Request the correct serial number from the customer before closing this case.',
            $summary->recommendation,
        );
    }

    public function test_english_output_contains_no_hindi_text(): void
    {
        $summary = $this->buildSuspiciousSerialSummary();

        $payload = implode("\n", [
            ...$summary->executiveSummary,
            $summary->opinion,
            $summary->recommendation,
            (string) $summary->serialInsight?->explanation,
            (string) $summary->serialInsight?->suggestedAction,
        ]);

        $this->assertDoesNotMatchRegularExpression('/[\x{0900}-\x{097F}]/u', $payload);
    }

    public function test_duplicate_ira_signals_are_merged(): void
    {
        $summary = $this->buildSuspiciousSerialSummary();

        $this->assertCount(1, $summary->executiveSummary);
        $this->assertStringNotContainsString('product code', implode(' ', $summary->executiveSummary));
        $this->assertStringNotContainsString('WhatsApp', $summary->recommendation);
        $this->assertStringNotContainsString('serial number needs verification', Str::lower($summary->opinion));
    }

    public function test_opinion_and_recommendation_are_single_sentence_actions(): void
    {
        $summary = $this->buildSuspiciousSerialSummary();

        $this->assertSame(1, substr_count($summary->opinion, '.'));
        $this->assertSame(1, substr_count($summary->recommendation, '.'));
        $this->assertLessThanOrEqual(4, count($summary->executiveSummary));
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

    private function buildSuspiciousSerialSummary(): \App\Data\AI\IRAExecutiveSummaryDTO
    {
        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-EXEC-ENGLISH',
            'serial_number' => '54SAXXC5514586',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'English Output Customer',
            'customer_phone' => '9123456789',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'English output case',
            'description' => 'Bad serial.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $context = AIContextFactory::make([
            'serialMissing' => false,
            'deviceModel' => 'MFS 110',
            'warrantyStatus' => 'Not Available',
            'customerSummary' => ['open_cases' => 1],
        ]);

        $response = app(\App\Services\AI\AIService::class)->buildBundle($incident)->response;

        return app(IRAExecutiveSummaryService::class)->build(
            incident: $incident,
            response: $response,
            context: $context,
            customerSummary: ['open_cases' => 1],
        );
    }
}
