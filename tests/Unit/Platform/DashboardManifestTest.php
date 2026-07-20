<?php

namespace Tests\Unit\Platform;

use App\Contracts\Platform\PlatformCardProvider;
use App\Data\Platform\PlatformCardDefinition;
use App\Data\Platform\PlatformCardPayload;
use App\Data\Platform\PlatformSectionDefinition;
use App\Enums\PlatformCardSize;
use App\Enums\PlatformHealthStatus;
use App\Models\User;
use App\Services\Platform\Concerns\InteractsWithPlatformCardDefinition;
use App\Services\Platform\DashboardManifest;
use App\Services\Platform\PlatformCardRegistry;
use App\Services\Platform\PlatformSectionRegistry;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardManifestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-07-20 11:30:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_sections_are_sorted_by_priority(): void
    {
        $manifest = $this->makeManifest();
        $manifest->registerSection(new PlatformSectionDefinition(id: 'workforce', title: 'Workforce', priority: 30));
        $manifest->registerSection(new PlatformSectionDefinition(id: 'platform_health', title: 'Platform Health', priority: 10));

        $ids = array_map(
            fn (PlatformSectionDefinition $section): string => $section->id,
            $manifest->sections(),
        );

        $this->assertSame(['platform_health', 'workforce'], $ids);
    }

    public function test_resolve_omits_empty_sections_and_groups_cards(): void
    {
        $manifest = $this->makeManifest();
        $manifest->registerSection(new PlatformSectionDefinition(id: 'platform_health', title: 'Platform Health', priority: 10));
        $manifest->registerSection(new PlatformSectionDefinition(id: 'workforce', title: 'Workforce', priority: 30));
        $manifest->registerCard($this->makeCard(
            id: 'health',
            section: 'platform_health',
            priority: 10,
            size: PlatformCardSize::Large,
        ));

        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $dashboard = $manifest->resolve($viewer);

        $this->assertCount(1, $dashboard->sections);
        $this->assertSame('platform_health', $dashboard->sections[0]['key']);
        $this->assertSame('health', $dashboard->sections[0]['cards'][0]->key);
        $this->assertSame(PlatformCardSize::Large, $dashboard->sections[0]['cards'][0]->size);
    }

    public function test_card_size_maps_to_expected_column_classes(): void
    {
        $this->assertSame('col-6 col-md-4 col-xl-2', PlatformCardSize::XSmall->columnClass());
        $this->assertSame('col-12 col-md-6 col-xl-3', PlatformCardSize::Small->columnClass());
        $this->assertSame('col-12 col-md-6 col-xl-4', PlatformCardSize::Medium->columnClass());
        $this->assertSame('col-12 col-lg-8 col-xl-6', PlatformCardSize::Large->columnClass());
        $this->assertSame('col-12', PlatformCardSize::Full->columnClass());
    }

    public function test_hidden_cards_are_excluded(): void
    {
        $manifest = $this->makeManifest();
        $manifest->registerSection(new PlatformSectionDefinition(id: 'platform_health', title: 'Platform Health', priority: 10));
        $manifest->registerCard($this->makeCard(
            id: 'hidden_card',
            section: 'platform_health',
            priority: 10,
            hidden: true,
        ));

        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $dashboard = $manifest->resolve($viewer);

        $this->assertSame([], $dashboard->sections);
    }

    public function test_external_module_can_register_card_without_core_edits(): void
    {
        $manifest = $this->makeManifest();
        $manifest->registerSection(new PlatformSectionDefinition(id: 'workforce', title: 'Workforce', priority: 30));
        $manifest->registerCard($this->makeCard(
            id: 'attendance_today',
            section: 'workforce',
            priority: 20,
            size: PlatformCardSize::Medium,
        ));

        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $dashboard = $manifest->resolve($viewer);

        $this->assertSame('workforce', $dashboard->sections[0]['key']);
        $this->assertSame('attendance_today', $dashboard->sections[0]['cards'][0]->key);
        $this->assertSame('col-12 col-md-6 col-xl-4', $dashboard->sections[0]['cards'][0]->columnClass());
    }

    private function makeManifest(): DashboardManifest
    {
        return new DashboardManifest(
            sectionRegistry: new PlatformSectionRegistry,
            cardRegistry: new PlatformCardRegistry,
        );
    }

    private function makeCard(
        string $id,
        string $section,
        int $priority,
        PlatformCardSize $size = PlatformCardSize::Large,
        bool $hidden = false,
    ): PlatformCardProvider {
        return new class($id, $section, $priority, $size, $hidden) implements PlatformCardProvider
        {
            use InteractsWithPlatformCardDefinition;

            public function __construct(
                private readonly string $cardId,
                private readonly string $sectionId,
                private readonly int $cardPriority,
                private readonly PlatformCardSize $cardSize,
                private readonly bool $cardHidden,
            ) {}

            public function definition(): PlatformCardDefinition
            {
                return new PlatformCardDefinition(
                    id: $this->cardId,
                    title: ucfirst(str_replace('_', ' ', $this->cardId)),
                    section: $this->sectionId,
                    priority: $this->cardPriority,
                    size: $this->cardSize,
                    hidden: $this->cardHidden,
                );
            }

            public function load(User $viewer): PlatformCardPayload
            {
                return PlatformCardPayload::fromDefinition(
                    definition: $this->definition(),
                    status: PlatformHealthStatus::Healthy,
                    generatedAt: now(),
                );
            }
        };
    }
}
