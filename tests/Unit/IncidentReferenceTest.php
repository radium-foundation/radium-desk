<?php

namespace Tests\Unit;

use App\Models\Incident;
use App\Services\IncidentReferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentReferenceTest extends TestCase
{
    use RefreshDatabase;

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
            'order_id' => \App\Models\Order::query()->create([
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
            'created_by' => \App\Models\User::factory()->create()->id,
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
            'order_id' => \App\Models\Order::query()->create([
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
            'created_by' => \App\Models\User::factory()->create()->id,
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
        Incident::query()->create([
            'order_id' => \App\Models\Order::query()->create([
                'order_id' => 'RD-GEN-1',
                'serial_number' => 'SN-GEN-1',
                'product_name' => 'MFS 110',
                'device_model' => 'MFS 110',
                'status' => 'active',
            ])->id,
            'reference_no' => 'SC-00005',
            'category' => 'General',
            'source' => 'call',
            'title' => 'Legacy',
            'description' => 'Test',
            'status' => 'open',
            'created_by' => \App\Models\User::factory()->create()->id,
        ]);

        $this->assertSame('SC00006', app(IncidentReferenceService::class)->generate());
    }

    public function test_issue_summary_prefers_title(): void
    {
        $incident = new Incident([
            'title' => 'Activation Issue',
            'description' => 'Long description',
        ]);

        $this->assertSame('Activation Issue', $incident->issueSummary());
    }
}
