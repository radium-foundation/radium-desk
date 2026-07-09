<?php

namespace Tests\Unit;

use App\Enums\IncidentSource;
use App\Enums\NewContactIntent;
use App\Models\Incident;
use App\Models\Order;
use App\Models\ReferenceSequence;
use App\Models\User;
use App\Services\CustomerIntakeService;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IncidentReferenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_display_reference_removes_hyphen_from_legacy_reference(): void
    {
        $incident = new Incident(['reference_no' => 'SC-00001']);

        $this->assertSame('SC00001', $incident->display_reference);
    }

    public function test_display_reference_keeps_modern_reference_format(): void
    {
        $incident = new Incident(['reference_no' => 'SC00042']);

        $this->assertSame('SC00042', $incident->display_reference);
    }

    public function test_parse_reference_sequence(): void
    {
        $cases = [
            'SC1' => 1,
            'SC00001' => 1,
            'SC-00001' => 1,
            '00001' => 1,
            '1' => 1,
            'ABC' => null,
        ];

        foreach ($cases as $query => $expected) {
            $this->assertSame($expected, Incident::parseReferenceSequence($query), "Failed for query: {$query}");
        }
    }

    public function test_matching_reference_scope_finds_legacy_and_modern_formats(): void
    {
        $legacy = Incident::query()->create([
            'order_id' => Order::query()->create([
                'order_id' => 'RD-REF-1',
                'serial_number' => 'SN-REF-1',
                'product_name' => 'MFS 110',
                'device_model' => 'MFS 110',
                'status' => 'active',
            ])->id,
            'reference_no' => 'SC-00099',
            'category' => 'General',
            'source' => 'call',
            'title' => 'Legacy case',
            'description' => 'Test',
            'status' => 'open',
            'created_by' => User::factory()->create()->id,
        ]);

        foreach (['SC99', 'SC00099', 'SC-00099', '00099', '99'] as $query) {
            $this->assertTrue(
                Incident::query()->matchingReference($query)->whereKey($legacy->id)->exists(),
                "Failed to match legacy reference using query: {$query}"
            );
        }
    }

    public function test_matching_reference_scope_finds_five_digit_reference_variants(): void
    {
        $incident = Incident::query()->create([
            'order_id' => Order::query()->create([
                'order_id' => 'RD-REF-2865',
                'serial_number' => 'SN-REF-2865',
                'product_name' => 'MFS 110',
                'device_model' => 'MFS 110',
                'status' => 'active',
            ])->id,
            'reference_no' => 'SC02865',
            'category' => 'General',
            'source' => 'call',
            'title' => 'Five digit case',
            'description' => 'Test',
            'status' => 'open',
            'created_by' => User::factory()->create()->id,
        ]);

        foreach (['SC02865', 'SC2865', 'SC-02865', 'SC-2865', '2865'] as $query) {
            $this->assertTrue(
                Incident::query()->matchingReference($query)->whereKey($incident->id)->exists(),
                "Failed to match reference using query: {$query}"
            );
        }
    }

    public function test_format_display_reference_matches_matching_reference_variants(): void
    {
        $sequence = 2865;

        $this->assertSame('SC02865', Incident::formatDisplayReference($sequence));
        $this->assertContains('SC02865', Incident::referenceMatchVariants($sequence));
        $this->assertContains('SC2865', Incident::referenceMatchVariants($sequence));
        $this->assertContains('SC-02865', Incident::referenceMatchVariants($sequence));
        $this->assertContains('SC-2865', Incident::referenceMatchVariants($sequence));
        $this->assertContains('2865', Incident::referenceMatchVariants($sequence));
    }

    public function test_generate_uses_sc_prefix_without_hyphen(): void
    {
        $this->createIncidentWithReference('SC-00005');
        $this->syncSequenceTo(5);

        $this->assertSame('SC00006', app(IncidentReferenceService::class)->generate());
    }

    public function test_sequence_continues_after_existing_max(): void
    {
        $this->createIncidentWithReference('SC08533');
        $this->syncSequenceTo(8533);

        $this->assertSame('SC08534', app(IncidentReferenceService::class)->generate());
        $this->assertSame(8534, ReferenceSequence::query()->find(ReferenceSequence::SC)?->current_value);
    }

    public function test_generate_produces_unique_sequential_references(): void
    {
        $service = app(IncidentReferenceService::class);
        $references = [];

        for ($index = 0; $index < 20; $index++) {
            $references[] = $service->generate();
        }

        $this->assertSame(20, count(array_unique($references)));

        foreach ($references as $reference) {
            $this->assertMatchesRegularExpression('/^SC\d{5}$/', $reference);
        }

        $this->assertSame(20, ReferenceSequence::query()->find(ReferenceSequence::SC)?->current_value);
    }

    public function test_generate_does_not_lock_incidents_table(): void
    {
        $this->syncSequenceTo(10);

        DB::flushQueryLog();
        DB::enableQueryLog();

        app(IncidentReferenceService::class)->generate();

        $queries = collect(DB::getQueryLog())
            ->pluck('query')
            ->map(fn (string $query): string => strtolower($query))
            ->implode("\n");

        $this->assertStringNotContainsString('from "incidents"', $queries);
        $this->assertStringNotContainsString('from `incidents`', $queries);
        $this->assertStringContainsString('reference_sequences', $queries);
    }

    public function test_new_contact_intake_allocates_reference_once(): void
    {
        $this->syncSequenceTo(41);

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = app(CustomerIntakeService::class)->createNewContact(
            user: $agent,
            intent: NewContactIntent::GeneralSupport,
            source: IncidentSource::Call,
            customerName: 'Single Allocate Caller',
            phone: '9876543210',
            serialNumber: null,
            product: null,
            notes: 'Office hours inquiry.',
            assignOnCreate: false,
        );

        $this->assertSame('SC00042', $incident->reference_no);
        $this->assertSame('INQ-SC00042', $incident->order->order_id);
        $this->assertSame(42, ReferenceSequence::query()->find(ReferenceSequence::SC)?->current_value);
    }

    public function test_format_reference_helper_matches_existing_output(): void
    {
        $this->assertSame('SC00001', IncidentReferenceService::formatReference(1));
        $this->assertSame('SC08533', IncidentReferenceService::formatReference(8533));
    }

    public function test_issue_summary_prefers_title(): void
    {
        $incident = new Incident([
            'title' => 'Activation Issue',
            'description' => 'Long description',
        ]);

        $this->assertSame('Activation Issue', $incident->issueSummary());
    }

    private function createIncidentWithReference(string $referenceNo): Incident
    {
        return Incident::query()->create([
            'order_id' => Order::query()->create([
                'order_id' => 'RD-'.uniqid(),
                'serial_number' => 'SN-'.uniqid(),
                'product_name' => 'MFS 110',
                'device_model' => 'MFS 110',
                'status' => 'active',
            ])->id,
            'reference_no' => $referenceNo,
            'category' => 'General',
            'source' => 'call',
            'title' => 'Test case',
            'description' => 'Test',
            'status' => 'open',
            'created_by' => User::factory()->create()->id,
        ]);
    }

    private function syncSequenceTo(int $value): void
    {
        ReferenceSequence::query()
            ->where('name', ReferenceSequence::SC)
            ->update(['current_value' => $value]);
    }
}
