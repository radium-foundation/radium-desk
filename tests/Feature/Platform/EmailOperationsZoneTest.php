<?php

namespace Tests\Feature\Platform;

use App\Enums\IncomingEmailClassification;
use App\Enums\IncomingEmailMessageStatus;
use App\Enums\PlatformZoneId;
use App\Models\AuditLog;
use App\Models\IncomingEmailMessage;
use App\Models\User;
use App\Services\Platform\PlatformEmailOperationsService;
use App\Services\Platform\Zones\PlatformZoneRegistry;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class EmailOperationsZoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'inbound_email.enabled' => true,
            'inbound_email.mailboxes' => [
                'support@radiumbox.com' => 'support',
            ],
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);

        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
    }

    private function createSuperadmin(): User
    {
        $user = User::factory()->create([
            'email' => 'email-ops-zone@test.com',
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $user;
    }

    public function test_email_operations_zone_is_registered(): void
    {
        $registry = app(PlatformZoneRegistry::class);

        $this->assertTrue($registry->has(PlatformZoneId::EmailOperations->value));
        $this->assertSame(
            'Email Operations',
            $registry->get(PlatformZoneId::EmailOperations->value)->definition()->title,
        );
    }

    public function test_platform_index_lists_email_operations_nav_tab(): void
    {
        $this->actingAs($this->createSuperadmin())
            ->get(route('admin.platform.index'))
            ->assertOk()
            ->assertSee('data-platform-zone="email_operations"', false)
            ->assertSee('Email Operations', false);
    }

    public function test_zone_refresh_returns_trusted_kpis_and_drilldowns(): void
    {
        IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'fixture',
            'provider_message_id' => 'eo-needs-1',
            'from_email' => 'buyer@example.com',
            'subject' => 'Need help',
            'preview' => 'Please help',
            'status' => IncomingEmailMessageStatus::NeedsReview,
            'classification' => IncomingEmailClassification::Support,
            'received_at' => now(),
            'processed_at' => now(),
        ]);

        IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'provider' => 'fixture',
            'provider_message_id' => 'eo-ignored-1',
            'from_email' => 'promo@example.com',
            'subject' => 'Sale',
            'preview' => 'Promo',
            'status' => IncomingEmailMessageStatus::Ignored,
            'classification' => IncomingEmailClassification::Promotional,
            'received_at' => now(),
            'processed_at' => now(),
        ]);

        AuditLog::query()->create([
            'event' => 'incoming_email.promoted_to_service_case',
            'auditable_type' => (new IncomingEmailMessage)->getMorphClass(),
            'auditable_id' => 1,
            'new_values' => ['incident_id' => 99],
            'user_id' => null,
        ]);

        $response = $this->actingAs($this->createSuperadmin())
            ->getJson(route('admin.platform.zones.show', ['zone' => 'email_operations']));

        $response->assertOk()
            ->assertJsonPath('key', 'email_operations');

        $html = (string) $response->json('html');

        $this->assertStringContainsString('Today’s Operations', $html);
        $this->assertStringContainsString('Needs Human', $html);
        $this->assertStringContainsString('Processing Pipeline', $html);
        $this->assertStringContainsString('admin/incoming-emails', $html);
        $this->assertStringContainsString('data-platform-email-operations', $html);
    }

    public function test_overview_service_hides_unavailable_assignment_metrics(): void
    {
        config(['inbound_email.enabled' => true]);

        $overview = app(PlatformEmailOperationsService::class)->overview(useCache: false);

        $this->assertTrue($overview['available']);
        $this->assertTrue($overview['enabled']);
        $this->assertArrayNotHasKey('ownership_preserved', $overview['assignment'] ?? []);
        $this->assertArrayNotHasKey('failures', $overview['assignment'] ?? []);
        $this->assertSame([], $overview['exceptions']);
    }

    public function test_disabled_inbound_email_marks_zone_disabled(): void
    {
        config(['inbound_email.enabled' => false]);

        $overview = app(PlatformEmailOperationsService::class)->overview(useCache: false);

        $this->assertTrue($overview['available']);
        $this->assertFalse($overview['enabled']);
        $this->assertSame('disabled', $overview['overall_status']);
    }
}
