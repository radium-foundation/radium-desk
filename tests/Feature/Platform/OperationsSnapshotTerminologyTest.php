<?php

namespace Tests\Feature\Platform;

use App\Data\Platform\PlatformZoneSnapshot;
use App\Enums\PlatformAlertSeverity;
use App\Enums\PlatformHealthStatus;
use App\Enums\PlatformZoneId;
use App\Models\User;
use App\Services\Platform\Alerts\Contributors\ExecutiveSnapshotAlertContributor;
use App\Services\Platform\Zones\PlatformZoneSnapshotStore;
use App\Support\Platform\OperationsSnapshotPresentation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class OperationsSnapshotTerminologyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
    }

    public function test_zone_id_presentation_label_is_operations_snapshot(): void
    {
        $this->assertSame('executive_snapshot', PlatformZoneId::ExecutiveSnapshot->value);
        $this->assertSame(
            OperationsSnapshotPresentation::TITLE,
            PlatformZoneId::ExecutiveSnapshot->label(),
        );
    }

    public function test_status_badge_labels_are_operations_prefixed(): void
    {
        $this->assertSame('Operations Critical', OperationsSnapshotPresentation::statusLabel(PlatformHealthStatus::Critical));
        $this->assertSame('Operations Warning', OperationsSnapshotPresentation::statusLabel(PlatformHealthStatus::Warning));
        $this->assertSame('Operations Healthy', OperationsSnapshotPresentation::statusLabel(PlatformHealthStatus::Healthy));
    }

    public function test_critical_alerts_contributor_uses_operations_terminology(): void
    {
        app(PlatformZoneSnapshotStore::class)->put(new PlatformZoneSnapshot(
            key: 'executive_snapshot',
            status: PlatformHealthStatus::Critical,
            statusLabel: OperationsSnapshotPresentation::statusLabel(PlatformHealthStatus::Critical),
            updatedAt: now(),
            summary: ['state' => 'ready', 'card_count' => 8],
            html: '<div>kpi</div>',
            available: true,
        ));

        $alerts = app(ExecutiveSnapshotAlertContributor::class)->alerts();

        $this->assertCount(1, $alerts);
        $this->assertSame('Operations Snapshot', $alerts[0]->title);
        $this->assertSame('Operational KPI status', $alerts[0]->summary);
        $this->assertSame('Operations Critical', $alerts[0]->status);
        $this->assertSame(PlatformAlertSeverity::Critical, $alerts[0]->severity);
        $this->assertSame('executive_snapshot', $alerts[0]->source);
    }

    public function test_expand_panel_shows_operations_status_and_card_count(): void
    {
        app(PlatformZoneSnapshotStore::class)->put(new PlatformZoneSnapshot(
            key: 'executive_snapshot',
            status: PlatformHealthStatus::Critical,
            statusLabel: 'Operations Critical',
            updatedAt: now(),
            summary: ['state' => 'ready', 'card_count' => 8],
            html: '<div>kpi</div>',
            available: true,
        ));

        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->actingAs($viewer)
            ->getJson(route('admin.platform.zones.expand', [
                'zone' => 'critical_alerts',
                'item' => 'executive_snapshot',
            ]))
            ->assertOk()
            ->assertJsonPath('zone', 'critical_alerts')
            ->assertSee('Operations status: Critical', false)
            ->assertSee('Affected KPI cards: 8', false)
            ->assertSee('Operations Snapshot', false)
            ->assertDontSee('Executive metrics status', false);
    }

    public function test_platform_page_shows_operations_snapshot_title_and_tooltip(): void
    {
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->actingAs($viewer)
            ->get(route('admin.platform.index'))
            ->assertOk()
            ->assertSee('Operations Snapshot', false)
            ->assertSee(OperationsSnapshotPresentation::DESCRIPTION, false)
            ->assertSee(OperationsSnapshotPresentation::TOOLTIP, false)
            ->assertSee('Platform Health', false)
            ->assertDontSee('>Executive Snapshot<', false);
    }

    public function test_platform_health_healthy_and_operations_critical_read_naturally(): void
    {
        $this->assertSame('Healthy', PlatformHealthStatus::Healthy->label());
        $this->assertSame(
            'Operations Critical',
            OperationsSnapshotPresentation::statusLabel(PlatformHealthStatus::Critical),
        );
        $this->assertNotSame(
            PlatformHealthStatus::Critical->label(),
            OperationsSnapshotPresentation::statusLabel(PlatformHealthStatus::Critical),
        );
    }
}
