<?php

namespace Tests\Unit\Assignment;

use App\Enums\Assignment\SupportAgentAvailabilityStatus;
use App\Enums\TeamAvailabilityStatus;
use App\Models\User;
use App\Support\Assignment\Availability\SupportAssignmentAvailabilityResolver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportAssignmentAvailabilityResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_maps_production_available_status(): void
    {
        $user = User::factory()->create([
            'availability_status' => TeamAvailabilityStatus::Available,
        ]);

        $resolver = app(SupportAssignmentAvailabilityResolver::class);

        $this->assertSame(
            SupportAgentAvailabilityStatus::Available,
            $resolver->resolve($user),
        );
        $this->assertTrue($resolver->isAssignable($user));
    }

    public function test_maps_production_busy_status_to_assignable_available(): void
    {
        $user = User::factory()->create([
            'availability_status' => TeamAvailabilityStatus::Busy,
        ]);

        $resolver = app(SupportAssignmentAvailabilityResolver::class);

        $this->assertSame(
            SupportAgentAvailabilityStatus::Available,
            $resolver->resolve($user),
        );
        $this->assertTrue($resolver->isAssignable($user));
    }

    public function test_maps_production_offline_status(): void
    {
        $user = User::factory()->create([
            'availability_status' => TeamAvailabilityStatus::Offline,
        ]);

        $resolver = app(SupportAssignmentAvailabilityResolver::class);

        $this->assertSame(
            SupportAgentAvailabilityStatus::Offline,
            $resolver->resolve($user),
        );
        $this->assertFalse($resolver->isAssignable($user));
    }

    public function test_future_statuses_exist_for_architecture(): void
    {
        $this->assertTrue(SupportAgentAvailabilityStatus::Lunch->isAssignableForSupport());
        $this->assertFalse(SupportAgentAvailabilityStatus::Unavailable->isAssignableForSupport());
    }
}
